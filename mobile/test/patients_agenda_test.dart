import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:medicore_mobile/core/api_client.dart';
import 'package:medicore_mobile/screens/appointment_screens.dart';
import 'package:medicore_mobile/screens/patient_screens.dart';

import 'fakes.dart';

ApiClient client(Future<http.Response> Function(http.Request) handler) =>
    ApiClient(
        baseUrl: 'https://clinic.test/api/v1',
        store: MemoryTokenStore(),
        transport: MockClient(handler),
      )
      ..user = {
        'id': 10,
        'role': 'secretary',
        'permissions': [
          'patients.view',
          'appointments.view',
          'appointments.write',
        ],
      };

void main() {
  for (final agenda in [false, true]) {
    testWidgets(
      '${agenda ? 'Agenda' : 'Patients'} : pagination sur écran étroit',
      (tester) async {
        tester.view.physicalSize = const Size(320, 640);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        String? page;
        final api = client((request) async {
          page = request.url.queryParameters['page'];
          final pagination = {
            'data': [],
            'current_page': int.parse(page!),
            'next_page_url': page == '1' ? 'https://clinic.test/next' : null,
          };
          return http.Response(
            jsonEncode(
              agenda
                  ? {
                      'date': '2030-01-07',
                      'timezone': 'Africa/Porto-Novo',
                      'appointments': pagination,
                      'doctors': [],
                      'statuses': {},
                    }
                  : pagination,
            ),
            200,
          );
        });
        await tester.pumpWidget(
          MaterialApp(
            home: Scaffold(
              body: agenda ? AgendaScreen(api: api) : PatientsScreen(api: api),
            ),
          ),
        );
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
        await tester.tap(find.text('Suivant'));
        await tester.pumpAndSettle();
        expect(page, '2');
        expect(find.text('Page 2'), findsOneWidget);
        expect(tester.takeException(), isNull);
        await tester.pumpWidget(const SizedBox.shrink());
        api.dispose();
      },
    );
  }

  testWidgets(
    'Un ancien rendez-vous avec médecin inactif peut être réaffecté sans erreur',
    (tester) async {
      final api = client((request) async {
        expect(request.method, 'GET');
        return http.Response(
          jsonEncode({
            'timezone': 'Africa/Porto-Novo',
            'doctors': [
              {'id': 2, 'name': 'Actif fictif'},
            ],
            'availability': [],
            'absences': [
              {
                'doctor_id': 1,
                'doctor': 'Ancien fictif',
                'starts_at': '2020-01-07T08:00',
                'ends_at': '2020-01-07T17:00',
              },
            ],
          }),
          200,
        );
      });
      await tester.pumpWidget(
        MaterialApp(
          home: BookingEditor(
            api: api,
            patientId: 1,
            booking: const {
              'id': 1,
              'doctor_id': 1,
              'doctor': 'Ancien fictif',
              'starts_at': '2020-01-07T09:00',
              'ends_at': '2020-01-07T09:30',
            },
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      expect(find.text('Ancien fictif (indisponible)'), findsOneWidget);
      expect(find.textContaining('Absence : Ancien fictif'), findsOneWidget);
      expect(
        tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
        isNull,
      );
      await tester.tap(find.text('Disponibilités du 2020-01-07'));
      await tester.pumpAndSettle();
      expect(find.byType(DatePickerDialog), findsOneWidget);
      expect(tester.takeException(), isNull);
      await tester.tap(find.text('Cancel'));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Actif fictif').last);
      await tester.pumpAndSettle();
      expect(
        tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
        isNotNull,
      );
      expect(find.textContaining('Absence : Ancien fictif'), findsNothing);
      await tester.pumpWidget(const SizedBox.shrink());
      api.dispose();
    },
  );

  testWidgets('La réservation reste désactivée sans médecin actif', (
    tester,
  ) async {
    final api = client(
      (_) async => http.Response(
        jsonEncode({
          'timezone': 'Africa/Porto-Novo',
          'doctors': [],
          'availability': [],
          'absences': [],
        }),
        200,
      ),
    );
    await tester.pumpWidget(
      MaterialApp(home: BookingEditor(api: api, patientId: 1)),
    );
    await tester.pumpAndSettle();
    expect(
      find.text('Aucun médecin actif. La réservation est indisponible.'),
      findsOneWidget,
    );
    expect(
      tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
      isNull,
    );
    await tester.pumpWidget(const SizedBox.shrink());
    api.dispose();
  });
}

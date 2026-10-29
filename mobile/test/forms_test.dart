import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:medicore_mobile/core/api_client.dart';
import 'package:medicore_mobile/screens/appointment_screens.dart';
import 'package:medicore_mobile/screens/patient_screens.dart';

import 'fakes.dart';

void main() {
  testWidgets(
    'La fiche patient transmet les champs et affiche le doublon serveur',
    (tester) async {
      Map<String, dynamic>? sent;
      final api = ApiClient(
        baseUrl: 'https://clinic.test/api/v1',
        store: MemoryTokenStore(),
        transport: MockClient((request) async {
          sent = jsonDecode(request.body) as Map<String, dynamic>;
          expect(request.method, 'POST');
          expect(request.url.path, '/api/v1/patients');
          return http.Response(
            '{"errors":{"first_name":["Une fiche porte déjà cette identité."]}}',
            422,
          );
        }),
      );
      await tester.pumpWidget(MaterialApp(home: PatientEditor(api: api)));
      await tester.enterText(find.byType(TextField).at(0), 'Alice');
      await tester.enterText(find.byType(TextField).at(1), 'Fictif');
      await tester.enterText(find.byType(TextField).at(2), '1990-01-01');
      await tester.ensureVisible(find.text('Enregistrer'));
      await tester.tap(find.text('Enregistrer'));
      await tester.pumpAndSettle();
      expect(sent?['first_name'], 'Alice');
      expect(sent?['birth_date'], '1990-01-01');
      expect(find.text('Une fiche porte déjà cette identité.'), findsOneWidget);
      await tester.pumpWidget(const SizedBox.shrink());
      api.dispose();
    },
  );

  testWidgets(
    'La réservation transmet le créneau et conserve le conflit serveur',
    (tester) async {
      Map<String, dynamic>? sent;
      final api = ApiClient(
        baseUrl: 'https://clinic.test/api/v1',
        store: MemoryTokenStore(),
        transport: MockClient((request) async {
          if (request.method == 'GET') {
            return http.Response(
              '{"date":"2026-11-03","timezone":"Africa/Porto-Novo","doctors":[{"id":2,"name":"Docteur fictif"}],"availability":[]}',
              200,
            );
          }
          sent = jsonDecode(request.body) as Map<String, dynamic>;
          expect(request.url.path, '/api/v1/appointments');
          return http.Response(
            '{"errors":{"starts_at":["Ce médecin a déjà un rendez-vous sur ce créneau."]}}',
            422,
          );
        }),
      );
      await tester.pumpWidget(
        MaterialApp(home: BookingEditor(api: api, patientId: 7)),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byType(DropdownButtonFormField<String>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Docteur fictif').last);
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField).at(0), '2026-11-03T10:00');
      await tester.enterText(find.byType(TextField).at(1), '2026-11-03T10:30');
      await tester.ensureVisible(find.text('Enregistrer le rendez-vous'));
      await tester.tap(find.text('Enregistrer le rendez-vous'));
      await tester.pumpAndSettle();
      expect(sent?['patient_id'], 7);
      expect(sent?['doctor_id'], '2');
      expect(sent?['starts_at'], '2026-11-03T10:00');
      expect(
        find.text('Ce médecin a déjà un rendez-vous sur ce créneau.'),
        findsOneWidget,
      );
      await tester.pumpWidget(const SizedBox.shrink());
      api.dispose();
    },
  );
}

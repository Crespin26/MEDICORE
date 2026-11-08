import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:medicore_mobile/core/api_client.dart';
import 'package:medicore_mobile/core/push_notifications.dart';
import 'package:medicore_mobile/screens/account_screen.dart';
import 'package:medicore_mobile/screens/notification_screens.dart';

import 'fakes.dart';

class FakePush implements PushGateway {
  bool allowed = true;
  int disabled = 0;
  final registrationsController = StreamController<String>.broadcast();
  final messageController = StreamController<void>.broadcast();
  @override
  bool get configured => true;
  @override
  Stream<String> get registrations => registrationsController.stream;
  @override
  Stream<void> get messages => messageController.stream;
  @override
  Future<bool> authorize({required bool request}) async => allowed;
  @override
  Future<String?> installationId() async => 'fictif-installation-12345';
  @override
  Future<void> disable() async {
    disabled++;
  }

  @override
  Future<void> dispose() async {
    await registrationsController.close();
    await messageController.close();
  }
}

const profile = {
  'id': 1,
  'name': 'Médecin fictif',
  'email': 'doctor@medicore.test',
  'role': 'doctor',
  'permissions': ['appointments.view'],
};
const defaults = {
  'appointment_updates': false,
  'appointment_reminders': false,
  'reminder_minutes': 60,
  'push_enabled': false,
  'push_available': true,
  'can_receive_appointments': true,
};

ApiClient client(Future<http.Response> Function(http.Request) handler) =>
    ApiClient(
      baseUrl: 'https://clinic.test/api/v1',
      store: MemoryTokenStore(),
      transport: MockClient(handler),
    )..user = Map<String, dynamic>.from(profile);

void main() {
  testWidgets(
    'Profil : unicité affichée puis sauvegarde des seuls nom et e-mail',
    (tester) async {
      var attempts = 0;
      final api = client((request) async {
        expect(request.method, 'PATCH');
        expect(request.url.path, '/api/v1/me');
        final body = jsonDecode(request.body) as Map;
        expect(body.keys.toSet(), {'name', 'email'});
        attempts++;
        if (attempts == 1) {
          return http.Response(
            jsonEncode({
              'errors': {
                'email': ['Adresse déjà utilisée.'],
              },
            }),
            422,
          );
        }
        return http.Response(
          jsonEncode({
            'data': {...profile, ...body},
          }),
          200,
        );
      });
      final push = FakePush();
      await tester.pumpWidget(
        AnimatedBuilder(
          animation: api,
          builder: (_, _) => MaterialApp(
            home: Scaffold(
              body: AccountScreen(api: api, push: push),
            ),
          ),
        ),
      );
      await tester.tap(find.text('Modifier mon profil'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextFormField).at(0), 'Nouveau nom');
      await tester.enterText(
        find.byType(TextFormField).at(1),
        'autre@medicore.test',
      );
      await tester.tap(find.text('Enregistrer le profil'));
      await tester.pumpAndSettle();
      expect(find.text('Adresse déjà utilisée.'), findsOneWidget);
      expect(api.user?['name'], 'Médecin fictif');
      await tester.tap(find.text('Enregistrer le profil'));
      await tester.pumpAndSettle();
      expect(api.user?['name'], 'Nouveau nom');
      expect(find.text('Profil enregistré.'), findsOneWidget);
      await tester.pumpWidget(const SizedBox.shrink());
      await push.dispose();
      api.dispose();
    },
  );

  testWidgets(
    'Boîte : lecture idempotente et pagination sans données cliniques',
    (tester) async {
      tester.view.physicalSize = const Size(320, 640);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      bool read = false;
      final calls = <String>[];
      final api = client((request) async {
        calls.add('${request.method} ${request.url.path}?${request.url.query}');
        if (request.method == 'PATCH') {
          expect(request.url.path, '/api/v1/notifications/fixture/read');
          read = true;
          return http.Response('', 204);
        }
        final page = request.url.queryParameters['page'];
        return http.Response(
          jsonEncode({
            'unread_count': read ? 0 : 1,
            'notifications': {
              'data': page == '2'
                  ? []
                  : [
                      {
                        'id': 'fixture',
                        'title': 'MediCore',
                        'body': 'Information disponible.',
                        'read_at': read ? '2026-11-05T09:00:00Z' : null,
                      },
                    ],
              'next_page_url': page == '2'
                  ? null
                  : 'https://clinic.test/api/v1/notifications?page=2',
            },
          }),
          200,
        );
      });
      await tester.pumpWidget(MaterialApp(home: NotificationsScreen(api: api)));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      await tester.tap(find.text('Marquer comme lue'));
      await tester.pumpAndSettle();
      expect(find.text('Lue'), findsOneWidget);
      expect(find.text('0 notification(s) non lue(s)'), findsOneWidget);
      await tester.tap(find.text('Suivant'));
      await tester.pumpAndSettle();
      expect(find.text('Aucune notification.'), findsOneWidget);
      expect(calls.last, contains('page=2'));
      await tester.pumpWidget(const SizedBox.shrink());
      api.dispose();
    },
  );

  testWidgets('Une notification ouvre la journée de son rendez-vous', (
    tester,
  ) async {
    String? requestedDate;
    final api = client((request) async {
      if (request.url.path.endsWith('/appointments')) {
        requestedDate = request.url.queryParameters['date'];
        return http.Response(
          jsonEncode({
            'date': requestedDate,
            'timezone': 'Africa/Porto-Novo',
            'appointments': {
              'data': [],
              'current_page': 1,
              'next_page_url': null,
            },
          }),
          200,
        );
      }
      return http.Response(
        jsonEncode({
          'unread_count': 0,
          'notifications': {
            'data': [
              {
                'id': 'fixture',
                'title': 'MediCore',
                'kind': 'reminder',
                'body': 'Information disponible.',
                'read_at': '2026-11-05T09:00:00Z',
                'appointment_date': '2026-11-10',
                'appointment_time': '2026-11-10T10:00',
              },
            ],
            'next_page_url': null,
          },
        }),
        200,
      );
    });
    await tester.pumpWidget(MaterialApp(home: NotificationsScreen(api: api)));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Rappel de rendez-vous'));
    await tester.pumpAndSettle();
    expect(requestedDate, '2026-11-10');
    expect(find.text('Agenda du 2026-11-10'), findsOneWidget);
    await tester.pumpWidget(const SizedBox.shrink());
    api.dispose();
  });

  testWidgets(
    'Préférences : refus OS, inscription explicite et désactivation globale',
    (tester) async {
      final writes = <(String, Map<String, dynamic>)>[];
      final api = client((request) async {
        if (request.method == 'GET') {
          return http.Response(jsonEncode({'data': defaults}), 200);
        }
        final body = Map<String, dynamic>.from(jsonDecode(request.body) as Map);
        writes.add((request.url.path, body));
        return request.url.path.endsWith('push-device')
            ? http.Response('', 204)
            : http.Response(
                jsonEncode({
                  'data': {...defaults, ...body},
                }),
                200,
              );
      });
      final push = FakePush()..allowed = false;
      await tester.pumpWidget(
        MaterialApp(
          home: NotificationPreferencesScreen(api: api, push: push),
        ),
      );
      await tester.pumpAndSettle();
      Future<void> toggle(String label) async {
        final tile = find.widgetWithText(SwitchListTile, label);
        await tester.ensureVisible(tile);
        await tester.tap(tile);
        await tester.pumpAndSettle();
      }

      Future<void> save() async {
        final button = find.text('Enregistrer les préférences');
        await tester.ensureVisible(button);
        await tester.tap(button);
        await tester.pumpAndSettle();
      }

      await toggle('Changements de rendez-vous');
      await toggle('Rappels de rendez-vous');
      await toggle('Notifications push sur mes appareils');
      await save();
      expect(writes, isEmpty);
      expect(
        find.textContaining('Autorisez les notifications'),
        findsOneWidget,
      );
      push.allowed = true;
      await toggle('Notifications push sur mes appareils');
      await save();
      expect(writes[0].$2['appointment_updates'], true);
      expect(writes[0].$2['appointment_reminders'], true);
      expect(writes[1].$1, '/api/v1/push-device');
      expect(writes[1].$2['installation_id'], 'fictif-installation-12345');
      expect(find.text('Préférences enregistrées.'), findsOneWidget);
      await toggle('Notifications push sur mes appareils');
      await save();
      expect(writes.last.$2['push_enabled'], false);
      expect(push.disabled, 1);
      await tester.pumpWidget(const SizedBox.shrink());
      await push.dispose();
      api.dispose();
    },
  );

  testWidgets(
    'Une erreur de chargement ne peut pas écraser les préférences existantes',
    (tester) async {
      final api = client((request) async {
        expect(request.method, 'GET');
        return http.Response('{}', 503);
      });
      final push = FakePush();
      await tester.pumpWidget(
        MaterialApp(
          home: NotificationPreferencesScreen(api: api, push: push),
        ),
      );
      await tester.pumpAndSettle();
      final button = tester.widget<FilledButton>(
        find.widgetWithText(FilledButton, 'Enregistrer les préférences'),
      );
      expect(button.onPressed, isNull);
      expect(find.text('Recharger'), findsOneWidget);
      await tester.pumpWidget(const SizedBox.shrink());
      await push.dispose();
      api.dispose();
    },
  );

  test(
    'Une inscription retardée ne se rattache pas à un compte déconnecté',
    () async {
      final response = Completer<http.Response>();
      int deviceWrites = 0;
      final api = client((request) async {
        if (request.method == 'GET') return response.future;
        deviceWrites++;
        return http.Response('', 204);
      });
      final push = FakePush();
      final session = MobilePushSession(api, push);
      push.registrationsController.add('fictif-installation-12345');
      await Future<void>.delayed(Duration.zero);
      api.user = null;
      response.complete(
        http.Response(
          jsonEncode({
            'data': {...defaults, 'push_enabled': true},
          }),
          200,
        ),
      );
      await Future<void>.delayed(Duration.zero);
      expect(deviceWrites, 0);
      await session.sync();
      expect(push.disabled, 1);
      await session.dispose();
      await push.dispose();
      api.dispose();
    },
  );
}

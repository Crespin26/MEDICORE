import 'package:flutter_test/flutter_test.dart';
import 'package:medicore_mobile/core/api_client.dart';

import 'fakes.dart';

void main() {
  const url = String.fromEnvironment('MEDICORE_LIVE_API_URL');
  test(
    'HTTP réel : profil, patients, rendez-vous, notification et révocation',
    () async {
      final store = MemoryTokenStore();
      final api = ApiClient(
        baseUrl: url,
        store: store,
        allowDevelopmentHttp: true,
      );
      await api.login(
        'secretary@medicore.test',
        'Recette-Fictive-2026!',
        'Test Flutter fictif',
      );
      expect(api.can('patients.view'), isTrue);
      final patients = await api.request(
        'GET',
        'patients',
        query: {'search': 'Alice'},
      );
      expect((patients['data'] as List).first['first_name'], 'Alice');
      final agenda = await api.request('GET', 'appointments');
      expect(agenda['timezone'], 'Africa/Porto-Novo');
      await api.updateProfile(
        'Secrétariat mobile fictif',
        api.user!['email'] as String,
      );
      expect(api.user!['name'], 'Secrétariat mobile fictif');
      await api.request(
        'PUT',
        'notification-preferences',
        body: {
          'appointment_updates': true,
          'appointment_reminders': true,
          'reminder_minutes': 60,
          'push_enabled': false,
        },
      );
      final preferences = await api.request('GET', 'notification-preferences');
      expect(preferences['data']['appointment_updates'], true);
      final tomorrow = DateTime.parse(agenda['date'] as String)
          .add(const Duration(days: 1))
          .toIso8601String()
          .substring(0, 10);
      final nextAgenda = await api.request(
        'GET',
        'appointments',
        query: {'date': tomorrow},
      );
      final availability = (nextAgenda['availability'] as List).first as Map;
      await api.request(
        'POST',
        'appointments',
        body: {
          'patient_id': (patients['data'] as List).first['id'],
          'doctor_id': availability['doctor_id'],
          'starts_at': '${tomorrow}T11:00',
          'ends_at': '${tomorrow}T11:30',
        },
      );
      final inbox = await api.request('GET', 'notifications');
      expect(inbox['unread_count'], 1);
      final notification =
          (inbox['notifications']['data'] as List).first as Map;
      expect(notification['kind'], 'created');
      expect(notification['appointment_date'], tomorrow);
      expect(notification['body'].toString().contains('Alice'), false);
      await api.request('PATCH', 'notifications/${notification['id']}/read');
      final readInbox = await api.request('GET', 'notifications');
      expect(readInbox['unread_count'], 0);
      await api.logout();
      expect(store.token, isNull);
      api.dispose();
    },
    skip: url.isEmpty
        ? 'Exécuter uniquement avec la fixture HTTP locale isolée.'
        : false,
  );
}

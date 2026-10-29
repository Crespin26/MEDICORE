import 'package:flutter_test/flutter_test.dart';
import 'package:medicore_mobile/core/api_client.dart';

import 'fakes.dart';

void main() {
  const url = String.fromEnvironment('MEDICORE_LIVE_API_URL');
  test(
    'HTTP réel : connexion, recherche patient, agenda puis révocation',
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
      await api.logout();
      expect(store.token, isNull);
      api.dispose();
    },
    skip: url.isEmpty
        ? 'Exécuter uniquement avec la fixture HTTP locale isolée.'
        : false,
  );
}

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:medicore_mobile/core/api_client.dart';

import 'fakes.dart';

void main() {
  test('Refuse HTTP non autorisé, URL avec secrets et chemin hors API', () {
    for (final url in [
      'http://example.org/api/v1',
      'http://127.0.0.1/api/v1',
      'https://user:secret@example.org/api/v1',
      'https://example.org/',
    ]) {
      expect(() => ApiClient(baseUrl: url), throwsFormatException);
    }
  });
  test(
    'Conserve uniquement le jeton et le transmet en Bearer sans redirection',
    () async {
      final store = MemoryTokenStore();
      final calls = <http.Request>[];
      final api = ApiClient(
        baseUrl: 'https://clinic.test/api/v1',
        store: store,
        transport: MockClient((request) async {
          calls.add(request);
          expect(request.followRedirects, isFalse);
          if (request.method == 'POST') {
            expect(jsonDecode(request.body)['password'], 'Fictif-secret');
            return http.Response(
              jsonEncode({
                'data': {
                  'token': 'fictif-token',
                  'user': {
                    'id': 1,
                    'name': 'Fictif',
                    'role': 'doctor',
                    'permissions': ['patients.view'],
                  },
                },
              }),
              201,
            );
          }
          expect(request.headers['Authorization'], 'Bearer fictif-token');
          return http.Response(
            jsonEncode({
              'data': {'id': 1, 'name': 'Fictif'},
            }),
            200,
          );
        }),
      );
      await api.login('doctor@medicore.test', 'Fictif-secret', 'Fictif');
      expect(store.token, 'fictif-token');
      expect(api.can('patients.view'), isTrue);
      await api.request('GET', 'me');
      expect(calls.length, 2);
      api.dispose();
    },
  );
  test('401 efface le jeton, le profil et notifie la déconnexion', () async {
    final store = MemoryTokenStore('fictif-expiré');
    final api = ApiClient(
      baseUrl: 'https://clinic.test/api/v1',
      store: store,
      transport: MockClient(
        (_) async => http.Response('{"message":"expired"}', 401),
      ),
    );
    int changes = 0;
    api.addListener(() {
      changes++;
    });
    await expectLater(
      api.restore(),
      throwsA(isA<ApiException>().having((e) => e.status, 'status', 401)),
    );
    expect(store.token, isNull);
    expect(api.user, isNull);
    expect(changes, 1);
    api.dispose();
  });
  test(
    '422 expose les erreurs de champs sans afficher la réponse brute',
    () async {
      final api = ApiClient(
        baseUrl: 'https://clinic.test/api/v1',
        store: MemoryTokenStore(),
        transport: MockClient(
          (_) async => http.Response(
            '{"message":"internal details","errors":{"first_name":["Prénom requis."]}}',
            422,
          ),
        ),
      );
      await expectLater(
        api.request('POST', 'patients', body: {}),
        throwsA(
          isA<ApiException>()
              .having((e) => e.errors['first_name'], 'champ', [
                'Prénom requis.',
              ])
              .having(
                (e) => e.message,
                'message',
                isNot(contains('internal details')),
              ),
        ),
      );
      api.dispose();
    },
  );
  test(
    'Déconnexion hors réseau retire immédiatement l’accès et le stockage local',
    () async {
      final store = MemoryTokenStore('fictif-token');
      final api = ApiClient(
        baseUrl: 'https://clinic.test/api/v1',
        store: store,
        transport: MockClient((request) async {
          if (request.method == 'DELETE') throw http.ClientException('Offline');
          return http.Response(
            '{"data":{"id":1,"name":"Fictif","permissions":[]}}',
            200,
          );
        }),
      );
      await api.restore();
      await api.logout();
      expect(api.user, isNull);
      expect(store.token, isNull);
      api.dispose();
    },
  );
}

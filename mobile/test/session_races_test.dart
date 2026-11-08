import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:medicore_mobile/core/api_client.dart';

import 'fakes.dart';

http.Response identity(int id) => http.Response(
  jsonEncode({
    'data': {
      'id': id,
      'permissions': ['patients.view'],
    },
  }),
  200,
);

http.Response session(int id) => http.Response(
  jsonEncode({
    'data': {
      'token': 'fictif-token-$id',
      'user': {
        'id': id,
        'permissions': ['patients.view'],
      },
    },
  }),
  201,
);

ApiClient client(
  TokenStore store,
  Future<http.Response> Function(http.Request) handle,
) => ApiClient(
  baseUrl: 'https://clinic.test/api/v1',
  store: store,
  transport: MockClient(handle),
);

/// Permet de retarder une opération du coffre sans délai arbitraire dans les tests.
class ControlledStore extends MemoryTokenStore {
  ControlledStore(super.token);
  Completer<void>? reading, writing, clearing;
  bool failNextWrite = false;
  final readStarted = Completer<void>();
  final writeStarted = Completer<void>();
  final clearStarted = Completer<void>();

  @override
  Future<String?> read() async {
    final snapshot = token;
    if (!readStarted.isCompleted) readStarted.complete();
    await reading?.future;
    return snapshot;
  }

  @override
  Future<void> write(String value) async {
    if (!writeStarted.isCompleted) writeStarted.complete();
    await writing?.future;
    if (failNextWrite) {
      failNextWrite = false;
      throw StateError('Coffre fictif indisponible');
    }
    token = value;
  }

  @override
  Future<void> clear() async {
    if (!clearStarted.isCompleted) clearStarted.complete();
    await clearing?.future;
    token = null;
  }
}

void main() {
  test('Un échec du coffre pendant une ancienne connexion épargne le nouveau compte', () async {
    final store = ControlledStore(null)
      ..writing = Completer<void>()
      ..failNextWrite = true;
    var logins = 0;
    final revoked = <String?>[];
    final api = client(store, (request) async {
      if (request.method == 'DELETE') {
        revoked.add(request.headers['Authorization']);
        return http.Response('', 204);
      }
      return session(++logins);
    });
    final first = api.login('first@medicore.test', 'Fictif-secret', 'Fixture');
    await store.writeStarted.future;
    final second = api.login(
      'second@medicore.test',
      'Fictif-secret',
      'Fixture',
    );
    // Laisse la seconde réponse HTTP être traitée avant de libérer l’ancienne écriture.
    await Future<void>.delayed(Duration.zero);
    store.writing!.complete();
    await Future.wait([first, second]);
    expect(api.user?['id'], 2);
    expect(store.token, 'fictif-token-2');
    expect(revoked, ['Bearer fictif-token-1']);
    api.dispose();
  });

  for (final reconnect in [false, true]) {
    test(
      'Une restauration tardive respecte ${reconnect ? 'la nouvelle connexion' : 'la déconnexion'}',
      () async {
        final started = Completer<void>();
        final delayed = Completer<http.Response>();
        final store = MemoryTokenStore('fictif-token-1');
        final api = client(store, (request) async {
          if (request.method == 'POST') return session(2);
          if (request.method == 'DELETE') return http.Response('', 204);
          started.complete();
          return delayed.future;
        });
        final pending = api.restore();
        await started.future;
        await api.logout();
        if (reconnect) {
          await api.login('second@medicore.test', 'Fictif-secret', 'Fixture');
        }
        delayed.complete(identity(1));
        await pending;
        expect(api.user?['id'], reconnect ? 2 : isNull);
        expect(store.token, reconnect ? 'fictif-token-2' : isNull);
        api.dispose();
      },
    );
  }

  test(
    'Une lecture ancienne du coffre ne rétablit pas le jeton après logout',
    () async {
      final store = ControlledStore('fictif-token-1')
        ..reading = Completer<void>();
      var calls = 0;
      final api = client(store, (_) async {
        calls++;
        return identity(1);
      });
      final restore = api.restore();
      await store.readStarted.future;
      final logout = api.logout();
      store.reading!.complete();
      await Future.wait([restore, logout]);
      expect(calls, 0);
      expect(api.user, isNull);
      expect(store.token, isNull);
      api.dispose();
    },
  );

  test(
    'Une connexion annulée révoque son jeton sans rouvrir le compte',
    () async {
      final started = Completer<void>();
      final delayed = Completer<http.Response>();
      final revoked = <String?>[];
      final store = MemoryTokenStore();
      final api = client(store, (request) async {
        if (request.method == 'DELETE') {
          revoked.add(request.headers['Authorization']);
          return http.Response('', 204);
        }
        started.complete();
        return delayed.future;
      });
      final pending = api.login(
        'first@medicore.test',
        'Fictif-secret',
        'Fixture',
      );
      await started.future;
      await api.logout();
      delayed.complete(session(1));
      await pending;
      expect(revoked, ['Bearer fictif-token-1']);
      expect(api.user, isNull);
      expect(store.token, isNull);
      api.dispose();
    },
  );

  test('Deux connexions concurrentes conservent la plus récente', () async {
    final started = Completer<void>();
    final delayed = Completer<http.Response>();
    final revoked = <String?>[];
    final store = MemoryTokenStore();
    final api = client(store, (request) async {
      if (request.method == 'DELETE') {
        revoked.add(request.headers['Authorization']);
        return http.Response('', 204);
      }
      if (jsonDecode(request.body)['email'] == 'first@medicore.test') {
        started.complete();
        return delayed.future;
      }
      return session(2);
    });
    final first = api.login('first@medicore.test', 'Fictif-secret', 'Fixture');
    await started.future;
    await api.login('second@medicore.test', 'Fictif-secret', 'Fixture');
    delayed.complete(session(1));
    await first;
    expect(api.user?['id'], 2);
    expect(store.token, 'fictif-token-2');
    expect(revoked, ['Bearer fictif-token-1']);
    api.dispose();
  });

  test('Une écriture en cours est effacée avant de persister la nouvelle connexion', () async {
    final store = ControlledStore(null)..writing = Completer<void>();
    var logins = 0;
    final api = client(
      store,
      (request) async => request.method == 'DELETE'
          ? http.Response('', 204)
          : session(++logins),
    );
    final first = api.login('first@medicore.test', 'Fictif-secret', 'Fixture');
    await store.writeStarted.future;
    final logout = api.logout();
    final second = api.login(
      'second@medicore.test',
      'Fictif-secret',
      'Fixture',
    );
    store.writing!.complete();
    await Future.wait([first, logout, second]);
    expect(api.user?['id'], 2);
    expect(store.token, 'fictif-token-2');
    api.dispose();
  });

  test('Un effacement 401 retardé ne supprime pas le nouveau jeton', () async {
    final store = ControlledStore('fictif-token-1')
      ..clearing = Completer<void>();
    final api = client(store, (request) async {
      if (request.method == 'POST') return session(2);
      if (request.url.path.endsWith('/me')) return identity(1);
      return http.Response('Not JSON', 401);
    });
    await api.restore();
    final pending = api.request('GET', 'patients');
    final failure = expectLater(
      pending,
      throwsA(isA<ApiException>().having((e) => e.status, 'status', 401)),
    );
    await store.clearStarted.future;
    expect(api.user, isNull);
    final login = api.login('second@medicore.test', 'Fictif-secret', 'Fixture');
    store.clearing!.complete();
    await Future.wait([failure, login]);
    expect(api.user?['id'], 2);
    expect(store.token, 'fictif-token-2');
    api.dispose();
  });

  test('Une réponse après dispose ne publie plus de profil', () async {
    final started = Completer<void>();
    final delayed = Completer<http.Response>();
    final api = client(MemoryTokenStore('fictif-token-1'), (_) async {
      started.complete();
      return delayed.future;
    });
    final pending = api.restore();
    await started.future;
    api.dispose();
    delayed.complete(identity(1));
    await pending;
    expect(api.user, isNull);
  });
}

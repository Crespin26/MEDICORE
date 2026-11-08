import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:medicore_mobile/core/api_client.dart';
import 'package:medicore_mobile/main.dart';

import 'fakes.dart';

const profile = {
  'id': 1,
  'name': 'Compte fictif privé',
  'role': 'cashier',
  'permissions': [],
};

void main() {
  testWidgets(
    'Un échec de vérification à la reprise masque le compte jusqu’à une nouvelle vérification',
    (tester) async {
      var offline = false;
      final api = ApiClient(
        baseUrl: 'https://clinic.test/api/v1',
        store: MemoryTokenStore('fictif-token'),
        transport: MockClient((_) async {
          if (offline) throw http.ClientException('Réseau fictif indisponible');
          return http.Response(jsonEncode({'data': profile}), 200);
        }),
      );
      await tester.pumpWidget(MediCoreApp(api: api));
      await tester.pumpAndSettle();
      expect(find.text('Compte fictif privé'), findsOneWidget);
      offline = true;
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(find.text('Vérifier ma session'), findsOneWidget);
      expect(find.text('Compte fictif privé'), findsNothing);
      offline = false;
      await tester.tap(find.text('Réessayer'));
      await tester.pumpAndSettle();
      expect(find.text('Compte fictif privé'), findsOneWidget);
      await tester.pumpWidget(const SizedBox.shrink());
      api.dispose();
    },
  );

  testWidgets(
    'Le clavier physique compte comme activité, puis cinq minutes sans action ferment la session',
    (tester) async {
      var now = DateTime(2030, 1, 7, 8);
      final store = MemoryTokenStore('fictif-token');
      final api = ApiClient(
        baseUrl: 'https://clinic.test/api/v1',
        store: store,
        transport: MockClient(
          (request) async => request.method == 'DELETE'
              ? http.Response('', 204)
              : http.Response(jsonEncode({'data': profile}), 200),
        ),
      );
      await tester.pumpWidget(MediCoreApp(api: api, now: () => now));
      await tester.pumpAndSettle();
      now = now.add(const Duration(minutes: 4));
      await tester.sendKeyDownEvent(LogicalKeyboardKey.keyA);
      await tester.sendKeyUpEvent(LogicalKeyboardKey.keyA);
      now = now.add(const Duration(minutes: 2));
      await tester.pump(const Duration(seconds: 30));
      expect(api.user, isNotNull);
      now = now.add(const Duration(minutes: 3));
      await tester.pump(const Duration(seconds: 30));
      await tester.pumpAndSettle();
      expect(api.user, isNull);
      expect(store.token, isNull);
      expect(find.text('Connexion du personnel'), findsOneWidget);
      await tester.pumpWidget(const SizedBox.shrink());
      api.dispose();
    },
  );
}

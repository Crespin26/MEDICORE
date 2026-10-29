import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:medicore_mobile/core/api_client.dart';
import 'package:medicore_mobile/main.dart';

import 'fakes.dart';

void main() {
  testWidgets('Affiche le formulaire sans données cliniques avant connexion', (
    tester,
  ) async {
    final api = ApiClient(
      baseUrl: 'https://clinic.test/api/v1',
      store: MemoryTokenStore(),
      transport: MockClient((_) async => http.Response('{}', 500)),
    );
    await tester.pumpWidget(MediCoreApp(api: api));
    await tester.pumpAndSettle();
    expect(find.text('Connexion du personnel'), findsOneWidget);
    expect(find.text('Se connecter'), findsOneWidget);
    await tester.pumpWidget(const SizedBox.shrink());
    api.dispose();
  });
  testWidgets(
    'Connecte le personnel et masque les modules interdits au caissier',
    (tester) async {
      final api = ApiClient(
        baseUrl: 'https://clinic.test/api/v1',
        store: MemoryTokenStore(),
        transport: MockClient(
          (_) async => http.Response(
            jsonEncode({
              'data': {
                'token': 'fictif-token',
                'user': {
                  'id': 1,
                  'name': 'Caissier fictif',
                  'email': 'cashier@medicore.test',
                  'role': 'cashier',
                  'permissions': [],
                },
              },
            }),
            201,
          ),
        ),
      );
      await tester.pumpWidget(MediCoreApp(api: api));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextFormField).at(0),
        'cashier@medicore.test',
      );
      await tester.enterText(find.byType(TextFormField).at(1), 'Fictif-secret');
      await tester.tap(find.text('Se connecter'));
      await tester.pumpAndSettle();
      expect(find.text('Caissier fictif'), findsOneWidget);
      expect(find.text('Patients'), findsNothing);
      expect(find.text('Agenda'), findsNothing);
      await tester.pumpWidget(const SizedBox.shrink());
      api.dispose();
    },
  );
  testWidgets(
    'Une session révoquée retire aussi la fiche ouverte de la navigation',
    (tester) async {
      final api = ApiClient(
        baseUrl: 'https://clinic.test/api/v1',
        store: MemoryTokenStore('fictif-token'),
        transport: MockClient((request) async {
          if (request.url.path.endsWith('/me')) {
            return http.Response(
              '{"data":{"id":1,"name":"Médecin fictif","role":"doctor","permissions":["patients.view"]}}',
              200,
            );
          }
          if (request.url.path.endsWith('/patients/1')) {
            return http.Response('{"message":"expired"}', 401);
          }
          return http.Response(
            '{"data":[{"id":1,"first_name":"Alice","last_name":"Fictif","birth_date":"1990-01-01"}],"current_page":1,"next_page_url":null}',
            200,
          );
        }),
      );
      await tester.pumpWidget(MediCoreApp(api: api));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Fictif Alice'));
      await tester.pumpAndSettle();
      expect(find.text('Connexion du personnel'), findsOneWidget);
      expect(find.text('Fictif Alice'), findsNothing);
      await tester.pumpWidget(const SizedBox.shrink());
      api.dispose();
    },
  );
  testWidgets('Le passage en arrière-plan masque les informations affichées', (
    tester,
  ) async {
    final api = ApiClient(
      baseUrl: 'https://clinic.test/api/v1',
      store: MemoryTokenStore('fictif-token'),
      transport: MockClient((request) async {
        if (request.url.path.endsWith('/me')) {
          return http.Response(
            '{"data":{"id":1,"name":"Médecin fictif","permissions":["patients.view"]}}',
            200,
          );
        }
        return http.Response(
          '{"data":[{"id":1,"first_name":"Alice","last_name":"Fictif","birth_date":"1990-01-01"}],"current_page":1,"next_page_url":null}',
          200,
        );
      }),
    );
    await tester.pumpWidget(MediCoreApp(api: api));
    await tester.pumpAndSettle();
    expect(find.text('Fictif Alice'), findsOneWidget);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    expect(find.text('Fictif Alice'), findsNothing);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(find.text('Fictif Alice'), findsOneWidget);
    await tester.pumpWidget(const SizedBox.shrink());
    api.dispose();
  });
}

import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';

import 'api_client.dart';

/// Interface injectable : les tests ne contactent ni Firebase ni un appareil réel.
abstract class PushGateway {
  bool get configured;
  Stream<String> get registrations;
  Stream<void> get messages;
  Future<bool> authorize({required bool request});
  Future<String?> installationId();
  Future<void> disable();
  Future<void> dispose();
}

/// Le SDK ne démarre que si le build est configuré et le personnel donne son accord.
class FirebasePushGateway implements PushGateway {
  final _tokens = StreamController<String>.broadcast();
  final _messages = StreamController<void>.broadcast();
  final List<StreamSubscription<dynamic>> _subscriptions = [];
  bool _initialized = false;
  bool _disposed = false;

  @override
  bool get configured =>
      const bool.fromEnvironment('MEDICORE_PUSH_ENABLED') &&
      !kIsWeb &&
      (defaultTargetPlatform == TargetPlatform.android ||
          defaultTargetPlatform == TargetPlatform.iOS);
  @override
  Stream<String> get registrations => _tokens.stream;
  @override
  Stream<void> get messages => _messages.stream;

  /// Vérifie l’autorisation existante à la reprise ; seul un choix utilisateur déclenche la demande OS.
  @override
  Future<bool> authorize({required bool request}) async {
    if (!configured || _disposed) return false;
    if (!_initialized) {
      await Firebase.initializeApp();
      if (_disposed) return false;
      _initialized = true;
      _subscriptions.add(
        FirebaseMessaging.instance.onRegistered.listen((id) {
          if (!_disposed) _tokens.add(id);
        }),
      );
      _subscriptions.add(
        FirebaseMessaging.onMessage.listen((_) {
          if (!_disposed) _messages.add(null);
        }),
      );
      _subscriptions.add(
        FirebaseMessaging.onMessageOpenedApp.listen((_) {
          if (!_disposed) _messages.add(null);
        }),
      );
      if (await FirebaseMessaging.instance.getInitialMessage() != null) {
        if (!_disposed) _messages.add(null);
      }
    }
    final settings = request
        ? await FirebaseMessaging.instance.requestPermission(
            alert: true,
            badge: true,
            sound: true,
          )
        : await FirebaseMessaging.instance.getNotificationSettings();
    final allowed =
        settings.authorizationStatus == AuthorizationStatus.authorized ||
        settings.authorizationStatus == AuthorizationStatus.provisional;
    await FirebaseMessaging.instance.setAutoInitEnabled(allowed);
    return allowed;
  }

  @override
  Future<String?> installationId() async {
    if (!_initialized) return null;
    if (defaultTargetPlatform == TargetPlatform.iOS &&
        await FirebaseMessaging.instance.getAPNSToken() == null) {
      return null;
    }
    final completer = Completer<String>();
    final subscription = FirebaseMessaging.instance.onRegistered.listen(
      (id) {
        if (!completer.isCompleted) completer.complete(id);
      },
      onError: (Object error) {
        if (!completer.isCompleted) completer.completeError(error);
      },
    );
    try {
      final result = await Future.wait<dynamic>([
        completer.future.timeout(const Duration(seconds: 20)),
        FirebaseMessaging.instance.register().timeout(
          const Duration(seconds: 20),
        ),
      ], eagerError: true);
      return result.first as String;
    } finally {
      await subscription.cancel();
    }
  }

  /// Retire le jeton FCM local au départ du compte ; le serveur révoque aussi son inscription de session.
  @override
  Future<void> disable() async {
    if (!_initialized) return;
    await FirebaseMessaging.instance.setAutoInitEnabled(false);
    await FirebaseMessaging.instance.unregister();
  }

  @override
  Future<void> dispose() async {
    _disposed = true;
    for (final subscription in _subscriptions) {
      await subscription.cancel();
    }
    await _tokens.close();
    await _messages.close();
  }
}

/// Réinscrit les jetons renouvelés uniquement pour le compte actuellement connecté et opt-in.
class MobilePushSession {
  MobilePushSession(this.api, this.gateway) {
    _subscription = gateway.registrations.listen((token) {
      unawaited(_register(token).catchError((_) {}));
    });
  }
  final ApiClient api;
  final PushGateway gateway;
  late final StreamSubscription<String> _subscription;
  bool _syncing = false;

  Future<void> _register(String token) async {
    final userId = api.user?['id'];
    if (userId == null || !api.can('appointments.view')) return;
    final preferences = await api.request('GET', 'notification-preferences');
    if (api.user?['id'] != userId ||
        preferences['data']['push_enabled'] != true) {
      return;
    }
    await api.request(
      'PUT',
      'push-device',
      body: {
        'installation_id': token,
        'platform': defaultTargetPlatform == TargetPlatform.iOS
            ? 'ios'
            : 'android',
      },
    );
  }

  /// Réessaie à la connexion/reprise sans afficher de dialogue d’autorisation automatiquement.
  Future<void> sync() async {
    if (!gateway.configured) return;
    if (api.user == null) {
      await gateway.disable();
      return;
    }
    if (_syncing || !api.can('appointments.view')) return;
    _syncing = true;
    final userId = api.user?['id'];
    try {
      final result = await api.request('GET', 'notification-preferences');
      if (api.user?['id'] != userId) return;
      if (result['data']['push_enabled'] != true) {
        await gateway.disable();
        return;
      }
      if (await gateway.authorize(request: false)) {
        final token = await gateway.installationId();
        if (token != null && api.user?['id'] == userId) await _register(token);
      }
    } finally {
      _syncing = false;
      if (api.user?['id'] != userId) {
        if (api.user == null) {
          await gateway.disable();
        } else {
          unawaited(sync().catchError((_) {}));
        }
      }
    }
  }

  Future<void> dispose() => _subscription.cancel();
}

import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

abstract class TokenStore {
  Future<String?> read();
  Future<void> write(String token);
  Future<void> clear();
}

class SecureTokenStore implements TokenStore {
  const SecureTokenStore();
  static const storage = FlutterSecureStorage();
  static const key = 'medicore.session.v1';
  @override
  Future<String?> read() => storage.read(key: key);
  @override
  Future<void> write(String token) => storage.write(key: key, value: token);
  @override
  Future<void> clear() => storage.delete(key: key);
}

class ApiException implements Exception {
  const ApiException(this.status, this.message, [this.errors = const {}]);
  final int status;
  final String message;
  final Map<String, List<String>> errors;
  @override
  String toString() => message;
}

class ApiClient extends ChangeNotifier {
  ApiClient({
    required String baseUrl,
    http.Client? transport,
    TokenStore? store,
    bool allowDevelopmentHttp = false,
  }) : base = Uri.parse(baseUrl.replaceAll(RegExp(r'/+$'), '')),
       transport = transport ?? http.Client(),
       store = store ?? const SecureTokenStore() {
    final local = [
      'localhost',
      '127.0.0.1',
      '10.0.2.2',
      '::1',
    ].contains(base.host);
    if (!base.hasAuthority ||
        base.userInfo.isNotEmpty ||
        base.hasQuery ||
        base.hasFragment ||
        !base.path.endsWith('/api/v1') ||
        (base.scheme != 'https' &&
            !(kDebugMode &&
                allowDevelopmentHttp &&
                local &&
                base.scheme == 'http'))) {
      throw const FormatException('Une URL HTTPS /api/v1 est requise.');
    }
  }
  final Uri base;
  final http.Client transport;
  final TokenStore store;
  String? _token;
  Map<String, dynamic>? user;
  int _sessionVersion = 0;

  /// Identifie les opérations du compte courant, même après reconnexion du même utilisateur.
  int get sessionGeneration => _sessionVersion;
  bool _disposed = false;
  Future<void> _storagePending = Future<void>.value();

  bool _isCurrent(int version) => !_disposed && version == _sessionVersion;

  /// Sérialise les accès au coffre : un ancien effacement ne doit pas supprimer un nouveau jeton.
  Future<T> _withStore<T>(Future<T> Function() action) {
    final result = _storagePending.then((_) => action());
    _storagePending = result.then<void>(
      (_) {},
      onError: (Object error, StackTrace stack) {},
    );
    return result;
  }

  /// Vérifie une permission pour adapter l’interface ; le serveur reste l’autorité.
  bool can(String permission) =>
      (user?['permissions'] as List? ?? []).contains(permission);

  /// Recharge le jeton sécurisé et vérifie sa validité auprès du serveur avant d’exposer le profil.
  Future<void> restore() async {
    final version = ++_sessionVersion;
    final token = await _withStore(store.read);
    if (!_isCurrent(version)) return;
    _token = token;
    if (token != null) {
      final data = await request('GET', 'me');
      if (!_isCurrent(version)) return;
      user = Map<String, dynamic>.from(data['data'] as Map);
      notifyListeners();
    }
  }

  /// Authentifie le personnel et persiste uniquement le jeton dans le stockage sécurisé.
  Future<void> login(String email, String password, String device) async {
    final version = ++_sessionVersion;
    final data = await _request(
      'POST',
      'session',
      token: null,
      body: {
        'email': email.trim(),
        'password': password,
        'device_name': device,
      },
    );
    final session = data['data'] as Map;
    final token = session['token'] as String;
    if (!_isCurrent(version)) {
      await _revoke(token);
      return;
    }
    _token = token;
    try {
      await _withStore(() async {
        if (_isCurrent(version)) await store.write(token);
      });
    } catch (_) {
      if (!_isCurrent(version)) {
        await _revoke(token);
        return;
      }
      await logout();
      throw const ApiException(0, 'Le stockage sécurisé est indisponible.');
    }
    if (!_isCurrent(version)) {
      await _revoke(token);
      return;
    }
    user = Map<String, dynamic>.from(session['user'] as Map);
    notifyListeners();
  }

  /// Actualise le profil en mémoire sans restaurer une session fermée pendant la requête.
  Future<void> updateProfile(String name, String email) async {
    final version = _sessionVersion;
    final userId = user?['id'];
    final result = await request(
      'PATCH',
      'me',
      body: {'name': name, 'email': email},
    );
    if (_isCurrent(version) && userId != null && user?['id'] == userId) {
      user = Map<String, dynamic>.from(result['data'] as Map);
      notifyListeners();
    }
  }

  /// Retire immédiatement l’accès local puis révoque le jeton serveur si le réseau le permet.
  Future<void> logout() async {
    ++_sessionVersion;
    // Start revocation with the current token, then remove local access immediately.
    final revoke = _token == null ? Future<void>.value() : _revoke(_token!);
    _token = null;
    user = null;
    final clear = _withStore(store.clear);
    if (!_disposed) notifyListeners();
    var storageFailed = false;
    try {
      await clear;
    } catch (_) {
      storageFailed = true;
    }
    await revoke;
    if (storageFailed) {
      throw const ApiException(0, 'Impossible d’effacer le stockage sécurisé.');
    }
  }

  /// Révoque le jeton capturé, sans toucher à une session ouverte entre-temps ; hors réseau, il expire côté serveur.
  Future<void> _revoke(String token) async {
    try {
      await _request('DELETE', 'session', token: token);
    } catch (_) {
      // La fermeture locale ne dépend pas de la disponibilité du réseau.
    }
  }

  /// Envoie une requête JSON bornée dans le temps, sans redirection ni exposition des erreurs internes.
  Future<Map<String, dynamic>> request(
    String method,
    String endpoint, {
    Map<String, dynamic>? body,
    Map<String, String>? query,
  }) => _request(method, endpoint, token: _token, body: body, query: query);

  Future<Map<String, dynamic>> _request(
    String method,
    String endpoint, {
    required String? token,
    Map<String, dynamic>? body,
    Map<String, String>? query,
  }) async {
    final version = _sessionVersion;
    final uri = base.replace(
      path: '${base.path}/$endpoint',
      queryParameters: query,
    );
    final req = http.Request(method, uri)..followRedirects = false;
    req.headers.addAll({
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    });
    final requestToken = token;
    if (requestToken != null) {
      req.headers['Authorization'] = 'Bearer $requestToken';
    }
    if (body != null) req.body = jsonEncode(body);
    try {
      final streamed = await transport
          .send(req)
          .timeout(const Duration(seconds: 20));
      final response = await http.Response.fromStream(streamed)
          .timeout(const Duration(seconds: 20));
      // A delayed response from an old session must not sign out a newer login.
      if (response.statusCode == 401 &&
          _isCurrent(version) &&
          requestToken != null &&
          _token == requestToken) {
        ++_sessionVersion;
        _token = null;
        user = null;
        final clear = _withStore(store.clear);
        notifyListeners();
        await clear;
      }
      if (response.statusCode == 401) {
        throw const ApiException(
          401,
          'Session expirée ou révoquée. Reconnectez-vous.',
        );
      }
      if (response.statusCode == 204) return {};
      final decoded = jsonDecode(response.body);
      if (decoded is! Map<String, dynamic>) throw const FormatException();
      if (response.statusCode >= 200 && response.statusCode < 300) {
        return decoded;
      }
      final errors = <String, List<String>>{};
      if (decoded['errors'] is Map) {
        (decoded['errors'] as Map).forEach((key, value) {
          errors[key.toString()] = value is List
              ? value.map((e) => e.toString()).toList()
              : [value.toString()];
        });
      }
      throw ApiException(response.statusCode, switch (response.statusCode) {
        401 => 'Session expirée ou révoquée. Reconnectez-vous.',
        403 => 'Votre rôle ne permet pas cette action.',
        422 => 'Vérifiez les champs saisis.',
        429 => 'Trop de tentatives. Réessayez dans un instant.',
        _ => 'Le serveur est indisponible. Réessayez.',
      }, errors);
    } on ApiException {
      rethrow;
    } on TimeoutException {
      throw const ApiException(0, 'Le serveur ne répond pas. Réessayez.');
    } on FormatException {
      throw const ApiException(0, 'Réponse du serveur inattendue.');
    } catch (_) {
      throw const ApiException(
        0,
        'Connexion impossible. Vérifiez votre réseau.',
      );
    }
  }

  @override
  void dispose() {
    _disposed = true;
    ++_sessionVersion;
    transport.close();
    super.dispose();
  }
}

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
  bool can(String permission) =>
      (user?['permissions'] as List? ?? []).contains(permission);

  Future<void> restore() async {
    _token = await store.read();
    if (_token != null) {
      final data = await request('GET', 'me');
      user = Map<String, dynamic>.from(data['data'] as Map);
      notifyListeners();
    }
  }

  Future<void> login(String email, String password, String device) async {
    final data = await request(
      'POST',
      'session',
      body: {
        'email': email.trim(),
        'password': password,
        'device_name': device,
      },
    );
    final session = data['data'] as Map;
    _token = session['token'] as String;
    try {
      await store.write(_token!);
    } catch (_) {
      await logout();
      throw const ApiException(0, 'Le stockage sécurisé est indisponible.');
    }
    user = Map<String, dynamic>.from(session['user'] as Map);
    notifyListeners();
  }

  Future<void> logout() async {
    // Start revocation with the current token, then remove local access immediately.
    final revoke = _token == null
        ? Future<Map<String, dynamic>>.value({})
        : request('DELETE', 'session');
    _token = null;
    user = null;
    notifyListeners();
    var storageFailed = false;
    try {
      await store.clear();
    } catch (_) {
      storageFailed = true;
    }
    try {
      await revoke;
    } catch (_) {
      /* Offline: the server token still expires. */
    }
    if (storageFailed) {
      throw const ApiException(0, 'Impossible d’effacer le stockage sécurisé.');
    }
  }

  Future<Map<String, dynamic>> request(
    String method,
    String endpoint, {
    Map<String, dynamic>? body,
    Map<String, String>? query,
  }) async {
    final uri = base.replace(
      path: '${base.path}/$endpoint',
      queryParameters: query,
    );
    final req = http.Request(method, uri)..followRedirects = false;
    req.headers.addAll({
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    });
    if (_token != null) req.headers['Authorization'] = 'Bearer $_token';
    if (body != null) req.body = jsonEncode(body);
    try {
      final streamed = await transport
          .send(req)
          .timeout(const Duration(seconds: 20));
      final response = await http.Response.fromStream(streamed)
          .timeout(const Duration(seconds: 20));
      if (response.statusCode == 204) return {};
      final decoded = jsonDecode(response.body);
      if (decoded is! Map<String, dynamic>) throw const FormatException();
      if (response.statusCode >= 200 && response.statusCode < 300) {
        return decoded;
      }
      if (response.statusCode == 401) {
        _token = null;
        user = null;
        try {
          await store.clear();
        } finally {
          notifyListeners();
        }
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
    transport.close();
    super.dispose();
  }
}

import 'dart:async';

import 'package:flutter/material.dart';

import 'core/api_client.dart';
import 'screens/clinic_home.dart';

void main() => runApp(
  MediCoreApp(
    api: ApiClient(
      baseUrl: const String.fromEnvironment(
        'MEDICORE_API_URL',
        defaultValue: 'https://medicore.example.invalid/api/v1',
      ),
      allowDevelopmentHttp: const bool.fromEnvironment('MEDICORE_ALLOW_HTTP'),
    ),
  ),
);

class MediCoreApp extends StatefulWidget {
  const MediCoreApp({super.key, required this.api});
  final ApiClient api;
  @override
  State<MediCoreApp> createState() => _MediCoreAppState();
}

class _MediCoreAppState extends State<MediCoreApp> with WidgetsBindingObserver {
  bool loading = true;
  bool shield = false;
  String? error;
  DateTime lastAction = DateTime.now();
  Timer? timer;
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    widget.api.addListener(changed);
    timer = Timer.periodic(const Duration(seconds: 30), (_) => checkIdle());
    restore();
  }

  void changed() {
    if (mounted) setState(() {});
  }

  Future<void> restore() async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      await widget.api.restore();
    } catch (e) {
      error = e is ApiException ? e.message : 'Stockage sécurisé indisponible.';
    }
    if (mounted) {
      setState(() {
        loading = false;
        shield = false;
      });
    }
  }

  void checkIdle() {
    if (widget.api.user != null &&
        DateTime.now().difference(lastAction) > const Duration(minutes: 5)) {
      unawaited(widget.api.logout().catchError((_) {}));
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) {
      if (mounted) {
        setState(() {
          shield = true;
        });
      }
    } else {
      checkIdle();
      if (widget.api.user != null) {
        restore();
      } else if (mounted) {
        setState(() {
          shield = false;
        });
      }
    }
  }

  @override
  void dispose() {
    timer?.cancel();
    widget.api.removeListener(changed);
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
    key: ValueKey(widget.api.user?['id'] ?? 'signed-out'),
    title: 'MediCore',
    debugShowCheckedModeBanner: false,
    theme: ThemeData(colorSchemeSeed: const Color(0xFF0F766E)),
    builder: (context, child) => shield || loading
        ? const Scaffold(
            body: Center(
              child: CircularProgressIndicator(
                semanticsLabel: 'MediCore — connexion en cours',
              ),
            ),
          )
        : Listener(
            onPointerDown: (_) {
              lastAction = DateTime.now();
            },
            child: child!,
          ),
    home: widget.api.user != null
        ? ClinicHome(api: widget.api)
        : LoginScreen(api: widget.api, error: error, retry: restore),
  );
}

class LoginScreen extends StatefulWidget {
  const LoginScreen({
    super.key,
    required this.api,
    this.error,
    required this.retry,
  });
  final ApiClient api;
  final String? error;
  final VoidCallback retry;
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final email = TextEditingController();
  final password = TextEditingController();
  final form = GlobalKey<FormState>();
  bool busy = false;
  String? error;
  Future<void> login() async {
    if (!form.currentState!.validate()) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.api.login(email.text, password.text, 'MediCore mobile');
    } catch (e) {
      if (mounted) {
        setState(() {
          error = e is ApiException
              ? e.message
              : 'Connexion impossible. Réessayez.';
        });
      }
    } finally {
      password.clear();
      if (mounted) {
        setState(() {
          busy = false;
        });
      }
    }
  }

  @override
  void dispose() {
    email.dispose();
    password.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('MediCore')),
    body: Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 480),
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Form(
            key: form,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  'Connexion du personnel',
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
                const SizedBox(height: 12),
                Text('Serveur : ${widget.api.base.host}'),
                const SizedBox(height: 20),
                TextFormField(
                  controller: email,
                  decoration: const InputDecoration(
                    labelText: 'Adresse e-mail',
                  ),
                  keyboardType: TextInputType.emailAddress,
                  autofillHints: const [AutofillHints.username],
                  validator: (value) => value?.contains('@') == true
                      ? null
                      : 'Adresse e-mail requise.',
                ),
                TextFormField(
                  controller: password,
                  decoration: const InputDecoration(labelText: 'Mot de passe'),
                  obscureText: true,
                  enableSuggestions: false,
                  autocorrect: false,
                  autofillHints: const [AutofillHints.password],
                  validator: (value) =>
                      value?.isNotEmpty == true ? null : 'Mot de passe requis.',
                ),
                if (error ?? widget.error case final String message)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    child: Semantics(liveRegion: true, child: Text(message)),
                  ),
                const SizedBox(height: 20),
                FilledButton(
                  onPressed: busy ? null : login,
                  child: Text(busy ? 'Connexion…' : 'Se connecter'),
                ),
                if (widget.error != null)
                  TextButton(
                    onPressed: busy ? null : widget.retry,
                    child: const Text('Réessayer la session enregistrée'),
                  ),
              ],
            ),
          ),
        ),
      ),
    ),
  );
}

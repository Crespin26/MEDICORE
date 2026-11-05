import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/push_notifications.dart';
import 'notification_screens.dart';

/// Présente l’identité du compte connecté et les réglages autorisés, sans données cliniques.
class AccountScreen extends StatelessWidget {
  const AccountScreen({super.key, required this.api, required this.push});
  final ApiClient api;
  final PushGateway push;

  @override
  Widget build(BuildContext context) => ListView(
    padding: const EdgeInsets.all(24),
    children: [
      Text(
        '${api.user?['name'] ?? ''}',
        style: Theme.of(context).textTheme.headlineSmall,
      ),
      Text('${api.user?['email'] ?? ''}'),
      Text('Rôle : ${api.user?['role'] ?? ''}'),
      const SizedBox(height: 24),
      FilledButton.icon(
        icon: const Icon(Icons.edit_outlined),
        label: const Text('Modifier mon profil'),
        onPressed: () async {
          final saved = await Navigator.of(context).push<bool>(
            MaterialPageRoute(builder: (_) => ProfileEditor(api: api)),
          );
          if (saved == true && context.mounted) {
            ScaffoldMessenger.of(
              context,
            ).showSnackBar(const SnackBar(content: Text('Profil enregistré.')));
          }
        },
      ),
      if (api.can('appointments.view')) ...[
        const SizedBox(height: 16),
        ListTile(
          leading: const Icon(Icons.notifications_outlined),
          title: const Text('Mes notifications'),
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(builder: (_) => NotificationsScreen(api: api)),
          ),
        ),
        ListTile(
          leading: const Icon(Icons.tune),
          title: const Text('Préférences de notification'),
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(
              builder: (_) =>
                  NotificationPreferencesScreen(api: api, push: push),
            ),
          ),
        ),
      ],
    ],
  );
}

class ProfileEditor extends StatefulWidget {
  const ProfileEditor({super.key, required this.api});
  final ApiClient api;
  @override
  State<ProfileEditor> createState() => _ProfileEditorState();
}

class _ProfileEditorState extends State<ProfileEditor> {
  late final name = TextEditingController(
    text: widget.api.user?['name'] as String? ?? '',
  );
  late final email = TextEditingController(
    text: widget.api.user?['email'] as String? ?? '',
  );
  final form = GlobalKey<FormState>();
  Map<String, List<String>> errors = {};
  bool busy = false;
  String? error;

  /// Le serveur valide l’unicité ; les champs de rôle et statut ne font pas partie de la requête.
  Future<void> save() async {
    if (!form.currentState!.validate()) return;
    setState(() {
      busy = true;
      error = null;
      errors = {};
    });
    try {
      await widget.api.updateProfile(
        name.text.trim(),
        email.text.trim().toLowerCase(),
      );
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          error = e.message;
          errors = e.errors;
        });
      }
    } finally {
      if (mounted) {
        setState(() {
          busy = false;
        });
      }
    }
  }

  @override
  void dispose() {
    name.dispose();
    email.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Modifier mon profil')),
    body: Form(
      key: form,
      child: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          TextFormField(
            controller: name,
            enabled: !busy,
            maxLength: 255,
            decoration: InputDecoration(
              labelText: 'Nom',
              errorText: errors['name']?.join('\n'),
            ),
            validator: (v) =>
                v == null || v.trim().isEmpty ? 'Nom requis.' : null,
          ),
          TextFormField(
            controller: email,
            enabled: !busy,
            maxLength: 255,
            keyboardType: TextInputType.emailAddress,
            decoration: InputDecoration(
              labelText: 'Adresse e-mail',
              errorText: errors['email']?.join('\n'),
            ),
            validator: (v) =>
                v?.contains('@') == true ? null : 'Adresse e-mail requise.',
          ),
          if (error != null) Semantics(liveRegion: true, child: Text(error!)),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: busy ? null : save,
            child: Text(busy ? 'Enregistrement…' : 'Enregistrer le profil'),
          ),
        ],
      ),
    ),
  );
}

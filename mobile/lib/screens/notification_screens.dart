import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/push_notifications.dart';
import 'clinic_home.dart';
import 'appointment_screens.dart';

/// Boîte privée paginée ; le serveur revérifie le destinataire et les droits d’agenda.
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key, required this.api});
  final ApiClient api;
  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  late Future<Map<String, dynamic>> result;
  int page = 1;
  @override
  void initState() {
    super.initState();
    reload();
  }

  void reload() => setState(() {
    result = widget.api.request(
      'GET',
      'notifications',
      query: {'page': '$page'},
    );
  });

  /// Une notification déjà lue reste idempotente ; les erreurs conservent la liste disponible.
  Future<void> markRead(String id) async {
    try {
      await widget.api.request('PATCH', 'notifications/$id/read');
      if (mounted) reload();
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  /// Ouvre la journée autorisée sans utiliser de lien externe fourni par un message push.
  void openAgenda([String? date]) {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => Scaffold(
          appBar: AppBar(title: const Text('Mon agenda')),
          body: AgendaScreen(api: widget.api, initialDate: date),
        ),
      ),
    );
  }

  static const labels = {
    'created': 'Rendez-vous créé',
    'updated': 'Rendez-vous modifié',
    'cancelled': 'Rendez-vous annulé',
    'reminder': 'Rappel de rendez-vous',
  };

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: const Text('Mes notifications'),
      actions: [
        IconButton(
          onPressed: reload,
          tooltip: 'Actualiser',
          icon: const Icon(Icons.refresh),
        ),
      ],
    ),
    body: FutureBuilder<Map<String, dynamic>>(
      future: result,
      builder: (context, snapshot) {
        if (snapshot.hasError) {
          return ApiFailure(error: snapshot.error!, retry: reload);
        }
        if (!snapshot.hasData) {
          return const Center(child: CircularProgressIndicator());
        }
        final data = snapshot.data!;
        final pagination = data['notifications'] as Map;
        final items = pagination['data'] as List;
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text('${data['unread_count']} notification(s) non lue(s)'),
            TextButton.icon(
              icon: const Icon(Icons.calendar_month),
              label: const Text('Consulter mon agenda'),
              onPressed: () => openAgenda(),
            ),
            if (items.isEmpty)
              const Padding(
                padding: EdgeInsets.all(24),
                child: Text('Aucune notification.'),
              ),
            for (final item in items)
              Card(
                // L’action sous le contenu laisse le texte lisible sur les petits écrans.
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    ListTile(
                      leading: Icon(
                        item['read_at'] == null
                            ? Icons.notifications_active_outlined
                            : Icons.done,
                      ),
                      title: Text(
                        labels[item['kind']] ?? item['title'] as String,
                      ),
                      subtitle: Text(
                        [
                          if (item['appointment_time'] != null)
                            (item['appointment_time'] as String).replaceFirst(
                              'T',
                              ' à ',
                            ),
                          item['body'] as String,
                        ].join('\n'),
                      ),
                      onTap: () {
                        if (item['read_at'] == null) {
                          markRead(item['id'] as String);
                        }
                        openAgenda(item['appointment_date'] as String?);
                      },
                    ),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                      child: Align(
                        alignment: AlignmentDirectional.centerEnd,
                        child: item['read_at'] == null
                            ? TextButton(
                                onPressed: () => markRead(item['id'] as String),
                                child: const Text('Marquer comme lue'),
                              )
                            : const Text('Lue'),
                      ),
                    ),
                  ],
                ),
              ),
            Wrap(
              alignment: WrapAlignment.spaceBetween,
              crossAxisAlignment: WrapCrossAlignment.center,
              spacing: 8,
              runSpacing: 8,
              children: [
                TextButton(
                  onPressed: page > 1
                      ? () {
                          page--;
                          reload();
                        }
                      : null,
                  child: const Text('Précédent'),
                ),
                Text('Page $page'),
                TextButton(
                  onPressed: pagination['next_page_url'] != null
                      ? () {
                          page++;
                          reload();
                        }
                      : null,
                  child: const Text('Suivant'),
                ),
              ],
            ),
          ],
        );
      },
    ),
  );
}

class NotificationPreferencesScreen extends StatefulWidget {
  const NotificationPreferencesScreen({
    super.key,
    required this.api,
    required this.push,
  });
  final ApiClient api;
  final PushGateway push;
  @override
  State<NotificationPreferencesScreen> createState() =>
      _NotificationPreferencesScreenState();
}

class _NotificationPreferencesScreenState
    extends State<NotificationPreferencesScreen> {
  bool loading = true,
      busy = false,
      updates = false,
      reminders = false,
      push = false;
  bool available = false;
  bool loaded = false;
  int minutes = 60;
  String? error;
  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final result = await widget.api.request(
        'GET',
        'notification-preferences',
      );
      if (!mounted) return;
      final data = result['data'] as Map;
      setState(() {
        updates = data['appointment_updates'] == true;
        reminders = data['appointment_reminders'] == true;
        push = data['push_enabled'] == true;
        minutes = data['reminder_minutes'] as int;
        available = data['push_available'] == true && widget.push.configured;
        loading = false;
        loaded = true;
      });
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          loading = false;
          error = e.message;
        });
      }
    }
  }

  Map<String, dynamic> preferences() => {
    'appointment_updates': updates,
    'appointment_reminders': reminders,
    'reminder_minutes': minutes,
    'push_enabled': push,
  };

  /// L’autorisation OS est demandée après le choix explicite ; une inscription échouée n’est pas annoncée réussie.
  Future<void> save() async {
    setState(() {
      busy = true;
      error = null;
    });
    final userId = widget.api.user?['id'];
    final platform = Theme.of(context).platform == TargetPlatform.iOS
        ? 'ios'
        : 'android';
    try {
      String? token;
      if (push && available) {
        if (!await widget.push.authorize(request: true)) {
          if (mounted) {
            setState(() {
              push = false;
              error = 'Autorisez les notifications dans les paramètres du téléphone, ou conservez les alertes dans l’application.';
            });
          }
          return;
        }
        token = await widget.push.installationId();
        if (token == null) {
          if (mounted) {
            setState(() {
              error = 'L’inscription de cet appareil est indisponible. Réessayez dans un instant.';
            });
          }
          return;
        }
      }
      if (widget.api.user?['id'] != userId || userId == null) return;
      await widget.api.request(
        'PUT',
        'notification-preferences',
        body: preferences(),
      );
      if (widget.api.user?['id'] != userId || !mounted) return;
      if (push && token != null) {
        await widget.api.request(
          'PUT',
          'push-device',
          body: {'installation_id': token, 'platform': platform},
        );
      } else if (!push) {
        await widget.push.disable();
      }
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Préférences enregistrées.')),
        );
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          error = e.message;
        });
      }
    } catch (_) {
      if (mounted) {
        setState(() {
          error = 'Les notifications sur cet appareil sont indisponibles. Réessayez.';
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
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Préférences de notification')),
    body: loading
        ? const Center(child: CircularProgressIndicator())
        : ListView(
            padding: const EdgeInsets.all(24),
            children: [
              const Text(
                'Ces choix s’appliquent à votre compte. Aucun nom de patient ni contenu médical ne figure dans les alertes.',
              ),
              SwitchListTile(
                title: const Text('Changements de rendez-vous'),
                value: updates,
                onChanged: busy
                    ? null
                    : (value) => setState(() {
                        updates = value;
                      }),
              ),
              SwitchListTile(
                title: const Text('Rappels de rendez-vous'),
                value: reminders,
                onChanged: busy
                    ? null
                    : (value) => setState(() {
                        reminders = value;
                      }),
              ),
              DropdownButtonFormField<int>(
                initialValue: minutes,
                decoration: const InputDecoration(labelText: 'Délai du rappel'),
                items: const [
                  DropdownMenuItem(value: 15, child: Text('15 minutes avant')),
                  DropdownMenuItem(value: 30, child: Text('30 minutes avant')),
                  DropdownMenuItem(value: 60, child: Text('1 heure avant')),
                  DropdownMenuItem(value: 120, child: Text('2 heures avant')),
                  DropdownMenuItem(value: 1440, child: Text('24 heures avant')),
                ],
                onChanged: busy || !reminders
                    ? null
                    : (value) => setState(() {
                        minutes = value!;
                      }),
              ),
              SwitchListTile(
                title: const Text('Notifications push sur mes appareils'),
                value: push,
                onChanged: busy || (!available && !push)
                    ? null
                    : (value) => setState(() {
                        push = value;
                      }),
              ),
              if (!available)
                const Text(
                  'Les notifications sur cet appareil ne sont pas encore disponibles. La boîte de notifications reste accessible dans l’application.',
                ),
              if (error != null)
                Semantics(liveRegion: true, child: Text(error!)),
              const SizedBox(height: 24),
              FilledButton(
                onPressed: busy || !loaded ? null : save,
                child: Text(
                  busy ? 'Enregistrement…' : 'Enregistrer les préférences',
                ),
              ),
              if (error != null)
                TextButton(
                  onPressed: busy ? null : load,
                  child: const Text('Recharger'),
                ),
            ],
          ),
  );
}

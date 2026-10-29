import 'package:flutter/material.dart';

import '../core/api_client.dart';
import 'patient_screens.dart';
import 'appointment_screens.dart';

class ClinicHome extends StatefulWidget {
  const ClinicHome({super.key, required this.api});
  final ApiClient api;
  @override
  State<ClinicHome> createState() => _ClinicHomeState();
}

class _ClinicHomeState extends State<ClinicHome> {
  int selected = 0;
  @override
  Widget build(BuildContext context) {
    final api = widget.api;
    final tabs = <(String, IconData, Widget)>[
      if (api.can('patients.view'))
        ('Patients', Icons.people_outline, PatientsScreen(api: api)),
      if (api.can('appointments.view'))
        ('Agenda', Icons.calendar_month_outlined, AgendaScreen(api: api)),
      (
        'Mon compte',
        Icons.account_circle_outlined,
        Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  '${api.user?['name']}',
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
                Text('${api.user?['email']}'),
                Text('Rôle : ${api.user?['role']}'),
                const SizedBox(height: 16),
                const Text(
                  'Seuls les modules autorisés sont affichés. Les données restent contrôlées par le serveur.',
                ),
              ],
            ),
          ),
        ),
      ),
    ];
    return Scaffold(
      appBar: AppBar(
        title: const Text('MediCore'),
        actions: [
          IconButton(
            tooltip: 'Se déconnecter',
            icon: const Icon(Icons.logout),
            onPressed: () async {
              try {
                await api.logout();
              } catch (_) {
                /* Local access is already removed. */
              }
            },
          ),
        ],
      ),
      body: IndexedStack(
        index: selected.clamp(0, tabs.length - 1),
        children: tabs.map((tab) => tab.$3).toList(),
      ),
      bottomNavigationBar: tabs.length < 2
          ? null
          : NavigationBar(
              selectedIndex: selected,
              onDestinationSelected: (value) => setState(() {
                selected = value;
              }),
              destinations: tabs
                  .map(
                    (tab) => NavigationDestination(
                      icon: Icon(tab.$2),
                      label: tab.$1,
                    ),
                  )
                  .toList(),
            ),
    );
  }
}

class ApiFailure extends StatelessWidget {
  const ApiFailure({super.key, required this.error, required this.retry});
  final Object error;
  final VoidCallback retry;
  @override
  Widget build(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Semantics(
            liveRegion: true,
            child: Text(
              error is ApiException
                  ? error.toString()
                  : 'Impossible de charger les données.',
            ),
          ),
          const SizedBox(height: 12),
          FilledButton(onPressed: retry, child: const Text('Réessayer')),
        ],
      ),
    ),
  );
}

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import 'clinic_home.dart';

String day(DateTime value) =>
    '${value.year}-${value.month.toString().padLeft(2, '0')}-${value.day.toString().padLeft(2, '0')}';

class AgendaScreen extends StatefulWidget {
  const AgendaScreen({super.key, required this.api});
  final ApiClient api;
  @override
  State<AgendaScreen> createState() => _AgendaScreenState();
}

class _AgendaScreenState extends State<AgendaScreen> {
  late Future<Map<String, dynamic>> result;
  String? date;
  String? doctor;
  int page = 1;
  @override
  void initState() {
    super.initState();
    result = fetch();
  }

  /// Charge les résultats de la page ou du jour sélectionné selon les filtres courants.
  Future<Map<String, dynamic>> fetch() => widget.api.request(
    'GET',
    'appointments',
    query: {'page': '$page', 'date': ?date, 'doctor_id': ?doctor},
  );

  /// Relance le chargement serveur et remplace le résultat affiché sans cache local de données métier.
  void reload() => setState(() {
    result = fetch();
  });

  /// Demande une transition au serveur puis rafraîchit l’agenda ; affiche les refus métier.
  Future<void> changeStatus(Map<String, dynamic> booking, String status) async {
    try {
      await widget.api.request(
        'PATCH',
        'appointments/${booking['id']}/status',
        body: {'status': status},
      );
      if (mounted) reload();
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              e.errors.values.expand((e) => e).join('\n').isEmpty
                  ? e.message
                  : e.errors.values.expand((e) => e).join('\n'),
            ),
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Map<String, dynamic>>(
    future: result,
    builder: (context, snapshot) {
      if (snapshot.hasError) {
        return ApiFailure(error: snapshot.error!, retry: reload);
      }
      if (!snapshot.hasData) {
        return const Center(child: CircularProgressIndicator());
      }
      final data = snapshot.data!;
      final records = data['appointments'] as Map;
      final items = records['data'] as List;
      final current = DateTime.parse(data['date'] as String);
      return Column(
        children: [
          ListTile(
            title: Text('Agenda du ${data['date']}'),
            subtitle: Text('Horaires de la clinique · ${data['timezone']}'),
            trailing: IconButton(
              tooltip: 'Choisir une date',
              icon: const Icon(Icons.calendar_month),
              onPressed: () async {
                final value = await showDatePicker(
                  context: context,
                  initialDate: current,
                  firstDate: DateTime(2000),
                  lastDate: DateTime(2100),
                );
                if (value != null && mounted) {
                  date = day(value);
                  page = 1;
                  reload();
                }
              },
            ),
          ),
          if (widget.api.user?['role'] != 'doctor')
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: DropdownButtonFormField<String>(
                isExpanded: true,
                initialValue: doctor,
                decoration: const InputDecoration(labelText: 'Médecin'),
                items: [
                  const DropdownMenuItem(
                    value: '',
                    child: Text('Tous les médecins'),
                  ),
                  for (final item in data['doctors'] as List)
                    DropdownMenuItem(
                      value: '${item['id']}',
                      child: Text('${item['name']}'),
                    ),
                ],
                onChanged: (value) {
                  doctor = value == '' ? null : value;
                  page = 1;
                  reload();
                },
              ),
            ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: () async {
                reload();
                await result;
              },
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: [
                  if (items.isEmpty)
                    const ListTile(
                      title: Text('Aucun rendez-vous pour cette journée.'),
                    ),
                  for (final raw in items)
                    Card(
                      child: Padding(
                        padding: const EdgeInsets.all(12),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              '${raw['patient']}',
                              style: Theme.of(context).textTheme.titleMedium,
                            ),
                            Text(
                              '${raw['doctor']} · ${raw['starts_at']} → ${raw['ends_at']}',
                            ),
                            Text('${(data['statuses'] as Map)[raw['status']]}'),
                            Row(
                              children: [
                                if (widget.api.can('appointments.status'))
                                  PopupMenuButton<String>(
                                    tooltip: 'Changer le statut',
                                    onSelected: (value) => changeStatus(
                                      Map<String, dynamic>.from(raw as Map),
                                      value,
                                    ),
                                    itemBuilder: (_) => [
                                      for (final value
                                          in raw['transitions'] as List)
                                        if (widget.api.user?['role'] !=
                                                'doctor' ||
                                            [
                                              'arrived',
                                              'completed',
                                              'absent',
                                            ].contains(value))
                                          PopupMenuItem(
                                            value: value as String,
                                            child: Text(
                                              '${(data['statuses'] as Map)[value]}',
                                            ),
                                          ),
                                    ],
                                  ),
                                if (widget.api.can('appointments.write') &&
                                    [
                                      'scheduled',
                                      'confirmed',
                                    ].contains(raw['status']))
                                  TextButton(
                                    onPressed: () async {
                                      await Navigator.of(context).push(
                                        MaterialPageRoute<void>(
                                          builder: (_) => BookingEditor(
                                            api: widget.api,
                                            patientId: raw['patient_id'] as int,
                                            booking: Map<String, dynamic>.from(
                                              raw as Map,
                                            ),
                                          ),
                                        ),
                                      );
                                      if (mounted) reload();
                                    },
                                    child: const Text('Déplacer'),
                                  ),
                              ],
                            ),
                          ],
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceEvenly,
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
              Text('Page ${records['current_page']}'),
              TextButton(
                onPressed: records['next_page_url'] != null
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
  );
}

class BookingEditor extends StatefulWidget {
  const BookingEditor({
    super.key,
    required this.api,
    required this.patientId,
    this.booking,
  });
  final ApiClient api;
  final int patientId;
  final Map<String, dynamic>? booking;
  @override
  State<BookingEditor> createState() => _BookingEditorState();
}

class _BookingEditorState extends State<BookingEditor> {
  late String date =
      widget.booking?['starts_at'].toString().substring(0, 10) ??
      day(DateTime.now().add(const Duration(days: 1)));
  late final start = TextEditingController(
    text: widget.booking?['starts_at'] as String? ?? '${date}T09:00',
  );
  late final end = TextEditingController(
    text: widget.booking?['ends_at'] as String? ?? '${date}T09:30',
  );
  late String? doctor = widget.booking?['doctor_id']?.toString();
  late Future<Map<String, dynamic>> result;
  bool busy = false;
  Map<String, List<String>> errors = {};
  String? error;
  @override
  void initState() {
    super.initState();
    result = fetch();
  }

  /// Charge les résultats de la page ou du jour sélectionné selon les filtres courants.
  Future<Map<String, dynamic>> fetch() =>
      widget.api.request('GET', 'appointments', query: {'date': date});

  /// Transmet les champs du formulaire et affiche les erreurs de validation 422 renvoyées par l’API.
  Future<void> submit() async {
    setState(() {
      busy = true;
      errors = {};
      error = null;
    });
    try {
      await widget.api.request(
        widget.booking == null ? 'POST' : 'PATCH',
        widget.booking == null
            ? 'appointments'
            : 'appointments/${widget.booking!['id']}',
        body: {
          'patient_id': widget.patientId,
          'doctor_id': doctor,
          'starts_at': start.text.trim(),
          'ends_at': end.text.trim(),
        },
      );
      if (mounted) Navigator.of(context).pop();
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
    start.dispose();
    end.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(
        widget.booking == null
            ? 'Planifier un rendez-vous'
            : 'Déplacer le rendez-vous',
      ),
    ),
    body: FutureBuilder<Map<String, dynamic>>(
      future: result,
      builder: (context, snapshot) {
        if (snapshot.hasError) {
          return ApiFailure(
            error: snapshot.error!,
            retry: () => setState(() {
              result = fetch();
            }),
          );
        }
        if (!snapshot.hasData) {
          return const Center(child: CircularProgressIndicator());
        }
        final data = snapshot.data!;
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Text('Patient MC-${widget.patientId} · ${data['timezone']}'),
            DropdownButtonFormField<String>(
              isExpanded: true,
              initialValue: doctor,
              decoration: InputDecoration(
                labelText: 'Médecin',
                errorText: errors['doctor_id']?.join('\n'),
              ),
              items: [
                for (final item in data['doctors'] as List)
                  DropdownMenuItem(
                    value: '${item['id']}',
                    child: Text('${item['name']}'),
                  ),
              ],
              onChanged: busy
                  ? null
                  : (value) => setState(() {
                      doctor = value;
                    }),
            ),
            TextButton(
              onPressed: busy
                  ? null
                  : () async {
                      final value = await showDatePicker(
                        context: context,
                        initialDate: DateTime.parse(date),
                        firstDate: DateTime.now().subtract(
                          const Duration(days: 1),
                        ),
                        lastDate: DateTime(2100),
                      );
                      if (value != null && mounted) {
                        setState(() {
                          date = day(value);
                          start.text = '${date}T09:00';
                          end.text = '${date}T09:30';
                          result = fetch();
                        });
                      }
                    },
              child: Text('Disponibilités du $date'),
            ),
            for (final slot in (data['availability'] as List? ?? []))
              if (doctor == null || '${slot['doctor_id']}' == doctor)
                Text(
                  '${slot['doctor']} · ${slot['starts_at']} → ${slot['ends_at']}',
                ),
            TextField(
              controller: start,
              keyboardType: TextInputType.datetime,
              decoration: InputDecoration(
                labelText: 'Début (AAAA-MM-JJTHH:mm)',
                errorText: errors['starts_at']?.join('\n'),
              ),
            ),
            TextField(
              controller: end,
              keyboardType: TextInputType.datetime,
              decoration: InputDecoration(
                labelText: 'Fin (AAAA-MM-JJTHH:mm)',
                errorText: errors['ends_at']?.join('\n'),
              ),
            ),
            if (errors['patient_id'] != null)
              Text(errors['patient_id']!.join('\n')),
            if (error != null) Semantics(liveRegion: true, child: Text(error!)),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: busy ? null : submit,
              child: Text(
                busy ? 'Enregistrement…' : 'Enregistrer le rendez-vous',
              ),
            ),
          ],
        );
      },
    ),
  );
}

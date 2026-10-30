import 'package:flutter/material.dart';

import '../core/api_client.dart';
import 'clinic_home.dart';
import 'appointment_screens.dart';

class PatientsScreen extends StatefulWidget {
  const PatientsScreen({super.key, required this.api});
  final ApiClient api;
  @override
  State<PatientsScreen> createState() => _PatientsScreenState();
}

class _PatientsScreenState extends State<PatientsScreen> {
  final search = TextEditingController();
  late Future<Map<String, dynamic>> result;
  int page = 1;
  @override
  void initState() {
    super.initState();
    result = fetch();
  }

  /// Charge les résultats de la page ou du jour sélectionné selon les filtres courants.
  Future<Map<String, dynamic>> fetch() => widget.api.request(
    'GET',
    'patients',
    query: {'search': search.text, 'page': '$page'},
  );

  /// Relance le chargement serveur et remplace le résultat affiché sans cache local de données métier.
  void reload() => setState(() {
    result = fetch();
  });
  @override
  void dispose() {
    search.dispose();
    super.dispose();
  }

  /// Ouvre le formulaire patient puis recharge la liste après une sauvegarde réussie.
  Future<void> openEditor() async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(builder: (_) => PatientEditor(api: widget.api)),
    );
    if (mounted) reload();
  }

  @override
  Widget build(BuildContext context) => Column(
    children: [
      Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            Expanded(
              child: TextField(
                controller: search,
                decoration: const InputDecoration(
                  labelText: 'Rechercher un patient',
                ),
                onSubmitted: (_) {
                  page = 1;
                  reload();
                },
              ),
            ),
            IconButton(
              tooltip: 'Rechercher',
              onPressed: () {
                page = 1;
                reload();
              },
              icon: const Icon(Icons.search),
            ),
            if (widget.api.can('patients.write'))
              IconButton(
                tooltip: 'Nouveau patient',
                onPressed: openEditor,
                icon: const Icon(Icons.person_add_outlined),
              ),
          ],
        ),
      ),
      Expanded(
        child: FutureBuilder<Map<String, dynamic>>(
          future: result,
          builder: (context, snapshot) {
            if (snapshot.hasError) {
              return ApiFailure(error: snapshot.error!, retry: reload);
            }
            if (!snapshot.hasData) {
              return const Center(child: CircularProgressIndicator());
            }
            final data = snapshot.data!;
            final items = data['data'] as List;
            return Column(
              children: [
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
                          const ListTile(title: Text('Aucun patient trouvé.')),
                        for (final raw in items)
                          ListTile(
                            title: Text(
                              '${raw['last_name']} ${raw['first_name']}',
                            ),
                            subtitle: Text(
                              'MC-${raw['id']} · ${raw['birth_date']}',
                            ),
                            trailing: const Icon(Icons.chevron_right),
                            onTap: () async {
                              await Navigator.of(context).push(
                                MaterialPageRoute<void>(
                                  builder: (_) => PatientDetails(
                                    api: widget.api,
                                    id: raw['id'] as int,
                                  ),
                                ),
                              );
                              if (mounted) reload();
                            },
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
                    Text('Page ${data['current_page']}'),
                    TextButton(
                      onPressed: data['next_page_url'] != null
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
      ),
    ],
  );
}

class PatientDetails extends StatefulWidget {
  const PatientDetails({super.key, required this.api, required this.id});
  final ApiClient api;
  final int id;
  @override
  State<PatientDetails> createState() => _PatientDetailsState();
}

class _PatientDetailsState extends State<PatientDetails> {
  late Future<Map<String, dynamic>> result;
  @override
  void initState() {
    super.initState();
    reload();
  }

  /// Relance le chargement serveur et remplace le résultat affiché sans cache local de données métier.
  void reload() {
    result = widget.api.request('GET', 'patients/${widget.id}');
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text('Patient MC-${widget.id}')),
    body: FutureBuilder<Map<String, dynamic>>(
      future: result,
      builder: (context, snapshot) {
        if (snapshot.hasError) {
          return ApiFailure(
            error: snapshot.error!,
            retry: () => setState(reload),
          );
        }
        if (!snapshot.hasData) {
          return const Center(child: CircularProgressIndicator());
        }
        final patient = snapshot.data!['data'] as Map<String, dynamic>;
        final active = patient['archived_at'] == null;
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Text(
              '${patient['last_name']} ${patient['first_name']}',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            if (!active)
              const Text(
                'Fiche archivée — consultation des historiques uniquement.',
              ),
            for (final field in [
              ('Naissance', 'birth_date'),
              ('Téléphone', 'phone'),
              ('E-mail', 'email'),
              ('Adresse', 'address'),
            ])
              ListTile(
                title: Text(field.$1),
                subtitle: Text('${patient[field.$2] ?? 'Non renseigné'}'),
              ),
            if (active && widget.api.can('patients.write'))
              FilledButton(
                onPressed: () async {
                  await Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) =>
                          PatientEditor(api: widget.api, patient: patient),
                    ),
                  );
                  if (mounted) setState(reload);
                },
                child: const Text('Modifier la fiche'),
              ),
            if (active && widget.api.can('appointments.write'))
              OutlinedButton(
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) =>
                        BookingEditor(api: widget.api, patientId: widget.id),
                  ),
                ),
                child: const Text('Planifier un rendez-vous'),
              ),
          ],
        );
      },
    ),
  );
}

class PatientEditor extends StatefulWidget {
  const PatientEditor({super.key, required this.api, this.patient});
  final ApiClient api;
  final Map<String, dynamic>? patient;
  @override
  State<PatientEditor> createState() => _PatientEditorState();
}

class _PatientEditorState extends State<PatientEditor> {
  static const fields = [
    ('Prénom', 'first_name'),
    ('Nom', 'last_name'),
    ('Naissance (AAAA-MM-JJ)', 'birth_date'),
    ('Téléphone (facultatif)', 'phone'),
    ('E-mail (facultatif)', 'email'),
    ('Adresse (facultatif)', 'address'),
  ];
  late final controllers = {
    for (final field in fields)
      field.$2: TextEditingController(
        text: widget.patient?[field.$2]?.toString() ?? '',
      ),
  };
  Map<String, List<String>> errors = {};
  String? error;
  bool busy = false;

  /// Transmet les champs du formulaire et affiche les erreurs de validation 422 renvoyées par l’API.
  Future<void> submit() async {
    setState(() {
      busy = true;
      errors = {};
      error = null;
    });
    try {
      await widget.api.request(
        widget.patient == null ? 'POST' : 'PATCH',
        widget.patient == null
            ? 'patients'
            : 'patients/${widget.patient!['id']}',
        body: {
          for (final field in fields)
            field.$2: controllers[field.$2]!.text.trim(),
        },
      );
      if (mounted) Navigator.of(context).pop();
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          errors = e.errors;
          error = e.message;
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
    for (final controller in controllers.values) {
      controller.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(
        widget.patient == null ? 'Nouveau patient' : 'Modifier le patient',
      ),
    ),
    body: ListView(
      padding: const EdgeInsets.all(20),
      children: [
        for (final field in fields)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: TextField(
              controller: controllers[field.$2],
              decoration: InputDecoration(
                labelText: field.$1,
                errorText: errors[field.$2]?.join('\n'),
              ),
              keyboardType: switch (field.$2) {
                'phone' => TextInputType.phone,
                'email' => TextInputType.emailAddress,
                'birth_date' => TextInputType.datetime,
                _ => TextInputType.text,
              },
            ),
          ),
        if (error != null) Semantics(liveRegion: true, child: Text(error!)),
        FilledButton(
          onPressed: busy ? null : submit,
          child: Text(busy ? 'Enregistrement…' : 'Enregistrer'),
        ),
      ],
    ),
  );
}

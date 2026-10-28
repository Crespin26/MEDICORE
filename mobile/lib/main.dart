import 'package:flutter/material.dart';

void main() => runApp(const MediCoreApp());

class MediCoreApp extends StatelessWidget {
  const MediCoreApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'MediCore',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(colorSchemeSeed: const Color(0xFF0F766E)),
      home: Scaffold(
        appBar: AppBar(title: const Text('MediCore')),
        body: const Center(
          child: Padding(
            padding: EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.local_hospital_outlined, size: 64),
                SizedBox(height: 24),
                Text('Votre espace clinique mobile',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold)),
                SizedBox(height: 16),
                Text(
                  'Application en préparation. La connexion au serveur et les fonctionnalités cliniques seront ajoutées prochainement.',
                  textAlign: TextAlign.center,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

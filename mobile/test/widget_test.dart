import 'package:flutter_test/flutter_test.dart';
import 'package:medicore_mobile/main.dart';

void main() {
  testWidgets('Présente MediCore et son statut', (tester) async {
    await tester.pumpWidget(const MediCoreApp());
    expect(find.text('MediCore'), findsOneWidget);
    expect(find.text('Votre espace clinique mobile'), findsOneWidget);
  });
}

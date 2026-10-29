<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->runningUnitTests() && ! app()->isDownForMaintenance() && (DB::table('consultations')->exists() || DB::table('consultation_revisions')->exists())) {
            throw new RuntimeException('Mettre l’application en maintenance et arrêter les écritures avant de chiffrer les consultations existantes.');
        }
        // SQLite stores JSON as TEXT and does not enforce VARCHAR lengths.
        // Avoid rebuilding its referenced tables while migrating existing records.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('consultations', function (Blueprint $table) {
                $table->longText('reason')->change();
                $table->longText('observations')->nullable()->change();
                $table->longText('report')->nullable()->change();
                $table->longText('vitals')->nullable()->change();
            });
            Schema::table('consultation_revisions', function (Blueprint $table) {
                $table->longText('snapshot')->change();
                $table->longText('correction_reason')->nullable()->change();
            });
        }

        DB::transaction(function () {
            foreach ([
                'consultations' => ['reason', 'observations', 'report', 'vitals'],
                'consultation_revisions' => ['snapshot', 'correction_reason'],
            ] as $table => $columns) {
                DB::table($table)->orderBy('id')->chunkById(100, function ($rows) use ($table, $columns) {
                    foreach ($rows as $row) {
                        $values = [];
                        foreach ($columns as $column) {
                            $values[$column] = $row->$column === null ? null : Crypt::encryptString($row->$column);
                        }
                        DB::table($table)->where('id', $row->id)->update($values);
                    }
                });
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migration irréversible : restaurer une sauvegarde compatible en maintenance. Aucun retour automatique en clair.');
    }
};

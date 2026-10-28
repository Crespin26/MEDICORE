<?php

use App\Models\Patient;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->text('search_text')->default('');
            $table->string('email')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('identity_key', 64)->nullable()->unique();
        });
        // Preserve existing duplicates; reserve the key on the first record only.
        $seen = [];
        foreach (DB::table('patients')->orderBy('id')->get() as $patient) {
            DB::table('patients')->where('id', $patient->id)->update(['search_text' => Patient::searchText($patient->first_name, $patient->last_name, $patient->phone ?? '')]);
            $key = Patient::identityKey($patient->first_name, $patient->last_name, $patient->birth_date);
            if (! isset($seen[$key])) {
                DB::table('patients')->where('id', $patient->id)->update(['identity_key' => $key]);
                $seen[$key] = true;
            }
        }
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropUnique(['identity_key']);
            $table->dropColumn(['email', 'address', 'identity_key', 'search_text']);
        });
    }
};

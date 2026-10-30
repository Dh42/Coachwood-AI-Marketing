<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Anthropic key used to be a platform setting; it's a connection
     * now, like every other provider key. A saved key becomes a shared
     * "Anthropic" connection, left for someone to press "Check now".
     */
    public function up(): void
    {
        $row = DB::table('settings')->where('key', 'llm.anthropic_api_key')->first();

        if ($row) {
            $value = json_decode($row->value, true);
            $key = isset($value['encrypted']) ? Crypt::decryptString($value['encrypted']) : null;

            if (filled($key) && ! DB::table('connections')->where('plugin', 'anthropic')->exists()) {
                DB::table('connections')->insert([
                    'plugin' => 'anthropic',
                    'brand_id' => null,
                    'name' => 'Anthropic',
                    'config' => json_encode([]),
                    'secrets' => Crypt::encryptString(json_encode(['api_key' => $key])),
                    'status' => 'pending',
                    'created_by' => $row->updated_by,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('settings')->where('key', 'llm.anthropic_api_key')->delete();
        }
    }

    public function down(): void
    {
        // The key stays on the connection.
    }
};

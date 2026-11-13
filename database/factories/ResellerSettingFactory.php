<?php

namespace Database\Factories;

use App\Models\ResellerSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResellerSetting>
 */
class ResellerSettingFactory extends Factory
{
    /**
     * Define the model's default state: a reseller with its own name and
     * colour.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->reseller(),
            'brand_name' => 'Webku Hosting',
            'brand_color' => '#0f766e',
            'logo_path' => null,
            'nameservers' => null,
        ];
    }
}

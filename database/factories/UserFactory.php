<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 *
 * A CRM staff account. The `name` column the Breeze factory wrote was dropped in
 * add_crm_fields_to_users_table; a person is a first and last name here, and the
 * role decides what they can see.
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name'     => fake()->firstName(),
            'last_name'      => fake()->lastName(),
            'email'          => fake()->unique()->safeEmail(),
            // unique and NOT NULL-ish in practice: the Users page treats it as
            // the second way to identify a person
            'mobile_number'  => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role'           => 'salesperson',
            'is_active'      => true,
            'approval_status' => 'approved',
            'password'       => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /** @param  'admin'|'telecaller'|'salesperson'  $role */
    public function role(string $role): static
    {
        return $this->state(fn (array $attributes) => ['role' => $role]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /** Fresh from the sign-up page: switched off until an admin approves. */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false, 'approval_status' => 'pending']);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false, 'approval_status' => 'rejected']);
    }
}

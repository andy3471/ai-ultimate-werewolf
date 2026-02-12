<?php

namespace App\Services;

use App\Enums\GameRole;
use App\Roles\Bodyguard;
use App\Roles\Role;
use App\Roles\Seer;
use App\Roles\Villager;
use App\Roles\Werewolf;

class RoleRegistry
{
    /** @var array<string, Role> */
    protected array $roles = [];

    public function __construct()
    {
        $this->register(new Werewolf);
        $this->register(new Villager);
        $this->register(new Seer);
        $this->register(new Bodyguard);
    }

    public function register(Role $role): void
    {
        $this->roles[$role->id()->value] = $role;
    }

    public function get(GameRole $role): Role
    {
        return $this->roles[$role->value]
            ?? throw new \InvalidArgumentException("Role [{$role->value}] is not registered.");
    }

    /**
     * @return array<string, Role>
     */
    public function all(): array
    {
        return $this->roles;
    }

    /**
     * Get the roles that have a night action, ordered by their phase priority.
     *
     * @return Role[]
     */
    public function nightRoles(): array
    {
        return collect($this->roles)
            ->filter(fn (Role $role) => $role->nightPhase() !== null)
            ->values()
            ->all();
    }
}

<?php

declare(strict_types=1);

namespace bapersbw\Modules\Users;

use bapersbw\Database\Repository;

/**
 * Data access for the users module. Autowired from the {@see \bapersbw\Database\Connection},
 * so a webservice can simply declare it in its constructor:
 *
 *   public function __construct(private UsersRepository $users) {}
 */
final class UsersRepository extends Repository
{
    protected function table(): string
    {
        return 'users';
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->where(['username' => $username])[0] ?? null;
    }
}

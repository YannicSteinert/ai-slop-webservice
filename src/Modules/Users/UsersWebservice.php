<?php

declare(strict_types=1);

namespace bapersbw\Modules\Users;

use bapersbw\Attributes\Route;
use bapersbw\Http\Response;
use bapersbw\Webservice\Webservice;

/**
 * Example module. Mounted under "users" in config/modules.php, so every route
 * below is relative to /users.
 *
 *   GET    /users                      -> index()
 *   POST   /users                      -> store()
 *   GET    /users/{user_id}/show       -> show()
 *   GET    /users/{group_id}/members/{user_id} -> member()  (multiple params)
 *
 * To guard a route later, add e.g. #[Authorize('users.view')] above the method
 * (or on the class) and register that policy on the Gate — nothing else changes.
 */
final class UsersWebservice extends Webservice
{
    #[Route('GET', '/')]
    public function index(): Response
    {
        return $this->ok([
            'users' => [
                ['id' => 1, 'name' => 'Ada Lovelace'],
                ['id' => 2, 'name' => 'Alan Turing'],
            ],
        ]);
    }

    #[Route('POST', '/')]
    public function store(): Response
    {
        $name = (string) $this->request->input('name', '');

        return $this->created(['id' => 99, 'name' => $name]);
    }

    #[Route('GET', '/{user_id}/show')]
    public function show(int $user_id): Response
    {
        return $this->ok(['id' => $user_id, 'name' => 'Ada Lovelace']);
    }

    /** Demonstrates multiple route parameters in one path. */
    #[Route('GET', '/{group_id}/members/{user_id}')]
    public function member(int $group_id, int $user_id): Response
    {
        return $this->ok([
            'group_id' => $group_id,
            'user_id' => $user_id,
        ]);
    }
}

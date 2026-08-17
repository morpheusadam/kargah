<?php

namespace Modules\Site\Services;

/**
 * The people who can log into the website.
 *
 * ## This page reads and changes roles. It does not create or delete accounts.
 *
 * The three verbs are not equally safe and pretending they are is how a panel
 * becomes dangerous.
 *
 * **Creating** a WordPress user over REST means choosing a password, which is a
 * credential this application would be composing and transmitting on somebody
 * else's behalf. It is also the one operation with a good alternative already
 * built into WordPress: an invitation from wp-admin, where the person sets their
 * own password and Kargah never sees it.
 *
 * **Deleting** a user is the single most destructive call in the whole REST API,
 * because `DELETE /wp/v2/users/{id}` requires a `reassign` parameter deciding
 * what happens to everything they ever wrote — and omitting it deletes their
 * posts. A mis-click here does not lose a page, it loses an author's entire
 * body of work. That belongs behind wp-admin's own confirmation screen, which
 * spells the choice out.
 *
 * **Changing a role** is reversible, is the thing somebody actually needs from a
 * panel day to day — a contributor who should now be an author, a departing
 * freelancer who should drop to subscriber — and cannot destroy anything. So it
 * is the one write here.
 *
 * ## Roles are read from the site, never from a hard-coded list
 *
 * A WordPress install can have any roles at all: membership plugins add them,
 * WooCommerce adds `customer` and `shop_manager`, and a site can rename the
 * defaults. `GET /wp/v2/roles` is not part of core, so the available roles are
 * gathered from the users themselves plus WordPress's five built-ins — which is
 * honest about being a sample rather than a directory, and never offers a role
 * this install does not have.
 */
class SiteUsers
{
    public const REST = 'wp/v2/users';

    /**
     * The five roles core ships, in order of power.
     *
     * Present so that a site whose every user is an administrator still offers
     * somewhere to demote them to. Any role found on an actual user is merged
     * in on top of these.
     *
     * @return array<string, string>
     */
    public static function coreRoles(): array
    {
        return [
            'administrator' => 'Administrator',
            'editor' => 'Editor',
            'author' => 'Author',
            'contributor' => 'Contributor',
            'subscriber' => 'Subscriber',
        ];
    }

    public function __construct(private readonly WordPressSite $site) {}

    /**
     * One page of users.
     *
     * `context=edit` again, and here it is what makes the page possible at all:
     * without it WordPress omits `roles` and `email` entirely, and a user list
     * with neither is a list of names.
     *
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<array-key, mixed>>, total: int, pages: int}
     *
     * @throws SiteRequestFailed
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $query = array_filter([
            'context' => 'edit',
            'search' => $filters['search'] ?? null,
            'roles' => $filters['role'] ?? null,
            'page' => $page,
            'per_page' => $perPage,
            'orderby' => 'name',
            'order' => 'asc',
        ], fn ($value): bool => $value !== null && $value !== '');

        $result = $this->site->paginate(self::REST, $query);

        /** @var list<array<array-key, mixed>> $items */
        $items = array_values(array_filter($result['items'], 'is_array'));

        return ['items' => $items, 'total' => $result['total'], 'pages' => $result['pages']];
    }

    /**
     * Change what somebody is allowed to do.
     *
     * `roles` is an array even for one role — WordPress models it as a list and
     * sending a bare string is a 400 that says `rest_invalid_param` and nothing
     * about which param.
     *
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function setRole(int $id, string $role): array
    {
        return $this->site->post(self::REST.'/'.$id, ['roles' => [$role]]);
    }

    /**
     * The roles this install actually uses, plus core's five.
     *
     * @param  list<array<array-key, mixed>>  $items
     * @return array<string, string>
     */
    public static function rolesFound(array $items): array
    {
        $roles = self::coreRoles();

        foreach ($items as $item) {
            foreach ((array) ($item['roles'] ?? []) as $role) {
                if (! is_string($role) || $role === '') {
                    continue;
                }

                if (! array_key_exists($role, $roles)) {
                    // A role core does not ship — `shop_manager`, or whatever a
                    // membership plugin registered. Titled rather than left as a
                    // slug, since that is all WordPress gives us here.
                    $roles[$role] = ucwords(str_replace('_', ' ', $role));
                }
            }
        }

        return $roles;
    }

    /**
     * 🔴 Whether demoting this user would leave the site with no administrator.
     *
     * The failure it prevents is total and self-inflicted: demote the last
     * administrator and nobody can promote anybody back, including from
     * wp-admin, and the only way out is editing the database directly.
     *
     * Counted over the page in hand, which means it can only ever *under*-count
     * administrators on a site with more than one page of users — and
     * under-counting is the safe direction, because it blocks a change that
     * might have been fine rather than allowing one that is fatal. The page
     * says the count is from what it can see.
     *
     * @param  list<array<array-key, mixed>>  $items
     */
    public static function wouldRemoveLastAdministrator(array $items, int $id, string $newRole): bool
    {
        if ($newRole === 'administrator') {
            return false;
        }

        $administrators = [];

        foreach ($items as $item) {
            if (in_array('administrator', (array) ($item['roles'] ?? []), true)) {
                $administrators[] = (int) ($item['id'] ?? 0);
            }
        }

        return $administrators === [$id];
    }
}

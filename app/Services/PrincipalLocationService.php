<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\PrincipalLocation;

/**
 * The single reader/writer of saved locations.
 *
 * Locations belong to a principal, not to a user or a group: a user-principal
 * and a group-principal carry the same rows, and the `/me/locations` and
 * `/groups/{id}/locations` surfaces are two views of the same table.
 *
 * The "one default per principal" rule lives here, because it is the only
 * place that knows what a default is scoped to. `update()` and `delete()`
 * scope their lookup by `principal_id`, so a location that belongs to
 * another principal is simply not found — the caller turns that into a 404,
 * never a 403, because the caller has no business learning that the id
 * exists.
 */
final class PrincipalLocationService
{
    /**
     * @return list<array{id: int, name: string, address: string, is_default: bool}>
     */
    public function listFor(int $principalId): array
    {
        $rows = PrincipalLocation::where('principal_id', $principalId)
            ->orderBy('id')
            ->get();

        return array_map(static fn(PrincipalLocation $location): array => self::present($location), $rows->all());
    }

    /**
     * @param  array{name?: string, address?: string, is_default?: bool} $data
     * @return array{location: array{id: int, name: string, address: string, is_default: bool}}
     */
    public function create(int $principalId, array $data): array
    {
        $isDefault = ($data['is_default'] ?? false) === true;

        if ($isDefault) {
            $this->clearDefaults($principalId);
        }

        $location = PrincipalLocation::create([
            'principal_id' => $principalId,
            'name'         => trim((string) ($data['name'] ?? '')),
            'address'      => trim((string) ($data['address'] ?? '')),
            'is_default'   => $isDefault,
        ]);

        return ['location' => self::present($location)];
    }

    /**
     * @param  array{name?: string, address?: string, is_default?: bool} $data
     * @return array{location: array{id: int, name: string, address: string, is_default: bool}}|null
     */
    public function update(int $locationId, int $principalId, array $data): ?array
    {
        $location = PrincipalLocation::where('id', $locationId)
            ->where('principal_id', $principalId)
            ->first();

        if (!$location instanceof PrincipalLocation) {
            return null;
        }

        // A null is "not mentioned", not "set to nothing": the name and the
        // address are not nullable, and blanking one would be a silent loss.
        if (isset($data['name'])) {
            $location->name = trim((string) $data['name']);
        }

        if (isset($data['address'])) {
            $location->address = trim((string) $data['address']);
        }

        if (isset($data['is_default'])) {
            if ($data['is_default'] === true) {
                $this->clearDefaults($principalId, $locationId);
            }
            $location->is_default = $data['is_default'] === true;
        }

        $location->save();

        return ['location' => self::present($location)];
    }

    public function delete(int $locationId, int $principalId): bool
    {
        $location = PrincipalLocation::where('id', $locationId)
            ->where('principal_id', $principalId)
            ->first();

        if (!$location instanceof PrincipalLocation) {
            return false;
        }

        $location->delete();

        return true;
    }

    /**
     * @return array{id: int, name: string, address: string, is_default: bool}
     */
    private static function present(PrincipalLocation $location): array
    {
        return [
            'id'         => (int) $location->id,
            'name'       => (string) $location->name,
            'address'    => (string) $location->address,
            'is_default' => (bool) $location->is_default,
        ];
    }

    /**
     * A principal has at most one default location; promoting one demotes
     * the others. `$exceptId` keeps the promoted row out of the demotion.
     */
    private function clearDefaults(int $principalId, ?int $exceptId = null): void
    {
        $query = PrincipalLocation::where('principal_id', $principalId)
            ->where('is_default', true);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        $query->update(['is_default' => false]);
    }
}

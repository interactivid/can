<?php

namespace interactivid\Can;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait Can {

	/*
	 * A cache of the user's roles. Use <code>getRoles()</code> instead of accessing this variable.
	 */
	private $userRoles;

	/**
	 * A cache of the user's permissions. Use <code>getPermissions()</code> instead of accessing this variable.
	 */
	private $userPermissions;

	/**
	 * The user's current group.
	 */
	private $groupId;

	private $normalizedGroupAndParents;

	private $rootGroup;

    /**
     * Accepts a single role slug, and attaches that role to the user. Does nothing
     * if the user is already attached to the role.
     *
     * @param $roleSlug
     * @param null $groupId
     * @return null|object
     * @throws CanException
     */
    public function attachRole($roleSlug, $groupId = null)
    {
        if ($groupId === null) {
            $groupId = $this->getGroupId();
        }

		$role = Role::single($roleSlug);
		if (empty($role))
		{
			$rootGroup = $this->getRootGroup($groupId);

			// All custom roles are defined on the root group, so make sure we're checking the right group.
			$role = RoleCustom::single($roleSlug, ['group_id' => $this->getRootGroup($groupId)]);

			if (empty($role))
			{
				throw new CanException("There is no role with the slug: $roleSlug");
			}
		}

		$timeStr = Carbon::now()->toDateTimeString();

		// Note that if the user belongs to the role in a parent group, their role won't be added to the subgroup.
		// If they're added to a subgroup, then a parent group, they'll have two records in the group chain.
		// We may want to reconsider this design?
		if ($this->is($roleSlug, $groupId, true))
		{
			return $role;
		}

		DB::table(Config::get('can.user_role_table'))->insert([
			'roles_slug' => $roleSlug,
			'user_id' => $this->id,
			'group_id' => $groupId,
			'created_at' => $timeStr,
			'updated_at' => $timeStr
		]);

        $this->addPermissionsForRole($role, $timeStr, $groupId);
		$this->invalidateRoleCache();

		return $role;
	}

	/**
	 * After adding role, add permissions for that role to the user/permissions
	 * table to make can() a faster call.
	 *
	 * @param Role $role
	 * @param      $timeStr
     * @param      $groupId
	 */
	protected function addPermissionsForRole(Role $role, $timeStr, $groupId)
	{
		$newPermissions = $this->uniquePermissionsForRole($role, $groupId);
		if(count($newPermissions))
		{
			$permData = array_map(function($v) use ($timeStr, $groupId) {
				return [
					'permissions_slug' => $v->slug,
					'user_id' => $this->id,
					'group_id' => $groupId,
					'created_at' => $timeStr,
					'updated_at' => $timeStr
				];
			}, $newPermissions);

			DB::table(Config::get('can.user_permission_table'))->insert($permData);
			$this->invalidatePermissionCache();
		}
	}

    /**
     * Detach a role from the user
     *
     * @param $roleSlug
     * @param null $groupId
     * @return bool
     * @throws CanException
     */
	public function detachRole($roleSlug, $groupId = null)
	{
		if ($groupId === null)
			$groupId = $this->getGroupId();

		// todo - does this weed out wildcards?
		SlugContainer::validateOrDie($roleSlug, 'slug');

		// make sure the role to detach is among the attached roles
		$allRoleSlugs = $this->slugsFor( $this->getRoles($groupId) );

		if (!in_array($roleSlug, $allRoleSlugs, TRUE))
		{
			return false;
		}

		$this->doDetachRole($roleSlug, $groupId);

		$this->detachRolePermissions($roleSlug, $groupId);

		return true;
	}


	/**
	 * returns an array of slugs given an array of Role or Permission objects
	 *
	 * @param array $rolesOrPermissions
	 *
	 * @return array
	 */
	protected function slugsFor(array $rolesOrPermissions)
	{
		// todo - move this to slugcontainer. Change slugcontainer into some
		// other name.
		return array_map(function($v) {
			return $v->slug;
		}, $rolesOrPermissions);
	}

	protected function doDetachRole($roleSlug, $groupId)
	{
		DB::table(Config::get('can.user_role_table'))
			->where('user_id', $this->id)
			->where('roles_slug', $roleSlug)
			->where('group_id', $groupId)
			->delete();

		$this->invalidateRoleCache();
	}


	/**
	 * Remove the permissions for a role from the user. Permissions that have been explicitly set
	 * on the user and permissions that also belong to another of the user's role are not removed.
	 *
	 * @param $targetRoleSlug
	 * @param $userRoleSlugs
	 *
	 * @throws CanException
	 */
	protected function detachRolePermissions($targetRoleSlug, $groupId)
	{
		$targetRole = Role::single($targetRoleSlug);
		if (!$targetRole)
			$targetRole = RoleCustom::single($targetRoleSlug, ['group_id' => $this->getRootGroup($groupId)]);

		$uniqueRolePermissions = $this->uniquePermissionsForRole($targetRole, $groupId);

		$uniqueSlugs = array_map(function($o) {
			return $o->slug;
		}, $uniqueRolePermissions);

		// then delete what remains
		if (count($uniqueRolePermissions) > 0)
		{
			DB::table(Config::get('can.user_permission_table'))
				->where('user_id', $this->id)
				->where('group_id', $groupId)
				->whereIn('permissions_slug', $uniqueSlugs)
				->delete();

			$this->invalidatePermissionCache();
		}
	}

	/**
	 * Permissions added directly on the user can only be removed using
	 * detachPermission. Removing a role from the user that contains the
	 * added permission will NOT remove a permission added through this
	 * method.
	 *
	 * @param $permissionSlugs
	 *
	 * @return bool
	 */
	public function attachPermission($permissionSlug)
	{
		$exists = DB::table(Config::get('can.permission_table'))->where('slug', $permissionSlug)->count();

		if (count($exists))
		{
			DB::table(Config::get('can.user_permission_table'))->insert([
				'user_id' => $this->id,
				'permissions_slug' => $permissionSlug,
				'added_on_user' => 1
			]);

			$this->invalidatePermissionCache();

			return true;
		}

		return false;
	}

	/**
	 * Detach a permission from the user. This can only be called for permissions that were set explicitly
	 * on the user using <code>attachPermission()</code> and not for implicit permissions that are
	 * inherited through one of the user's roles.
	 *
	 * @param $permissionSlug
	 *
	 * @return bool
	 */
	public function detachPermission($permissionSlug)
	{
		// todo - allow a comma-separated list?
		$affected = DB::table(Config::get('can.user_permission_table'))
			->where('user_id', $this->id)
			->where('group_id', $this->getGroupId())
			->where('permissions_slug', $permissionSlug)
			->where('added_on_user', 1)
			->delete();

		if ($affected)
		{
			$this->invalidatePermissionCache();
		}

		return $affected > 0;
	}


	/**
	 * Determine whether the user has a role matching the arguments
	 *
	 * @param $roles string|array Can be a single fully- or partially-qualified role, or a pipe-separated list of them
	 *
	 * @return bool
	 */
	public function is($roles, $groupId = null, $onlyCurrentGroup = false)
	{
		if ($groupId === null)
			$groupId = $this->getGroupId();

		// todo - possibly refactor to use getRoles? then have detachRole use this?

		// Cascading roles need to be accounted for, i.e. if a user has a role somewhere in a parent, that role should cascade
		// down to the current group.
		$groupIds = $this->normalizeGroupAndParents($groupId);

		if ($onlyCurrentGroup === true)
			$query = DB::table(Config::get('can.user_role_table'))->where('user_id', $this->id)->where('group_id', $groupId);
		else
			$query = DB::table(Config::get('can.user_role_table'))->where('user_id', $this->id)->whereIn('group_id', $groupIds);

		$container = new SlugContainer($roles);
		$query = $container->buildSlugQuery($query, 'roles_slug');

		return count($query->get()) > 0;
	}


	/**
	 * Determine whether the user has permissions matching the arguments
	 *
	 * @param $permissions Can be a single fully- or partially-qualified permission, or a pipe-separated list of them
	 *
	 * @return bool
	 */
	public function can($permissions, $groupId = null)
	{
		if (!$groupId)
			$groupId = $this->getGroupId();

		$query = DB::table(Config::get('can.user_permission_table'))->where('user_id', $this->id)->where('group_id', $groupId);

		$container = new SlugContainer($permissions);
		$query = $container->buildSlugQuery($query, 'permissions_slug');

		return count($query->get()) > 0;
	}


	/**
	 * Get the user's roles
	 *
	 * @return array
	 */
	public function getRoles($groupId, $onlyCurrentGroup = false)
	{
//		if ($groupId === null)
//			$groupId = $this->getCurrentGroup()->id;

		if (!empty($this->userRoles))
		{
			return $this->userRoles;
		}

		$roleTable = Config::get('can.role_table');
		$roleCustomTable = Config::get('can.role_custom_table');
		$userRoleTable = Config::get('can.user_role_table');
/*
		$queryParams = [
			'joinKeyFirst' => $roleTable.'.slug',
			'joinKeySecond' => $userRoleTable.'.roles_slug',
			'userIdKey' => $userRoleTable.'.user_id',
			'userId' => $this->id,
		];

		$data = DB::table($roleTable)
			->join($userRoleTable, function($query) use($queryParams) {
				$query->on($queryParams['joinKeyFirst'], '=', $queryParams['joinKeySecond'])
					->where($queryParams['userIdKey'], '=', $queryParams['userId']);
			})
			->get([$roleTable.'.*']);

		$sql = "SELECT r.slug, r.name, r.description, ur.group_id
				FROM " . $roleTable . " r
				INNER JOIN " . $userRoleTable . " ur ON " . $roleTable . ".slug = " . $userRoleTable . ".roles_slug
				WHERE " . $userRoleTable . ".user_id = ? AND group_id IN (?)
				UNION
				SELECT rc.slug, rc.name, rc.description, ur.group_id
				FROM " . $roleCustomTable . " rc
				INNER JOIN " . $userRoleTable . " ur ON " . $roleCustomTable . ".slug = " . $userRoleTable . ".roles_slug
				WHERE " . $userRoleTable . ".user_id = ? AND group_id IN (?)
		";
*/
		$groupAndParents = $this->normalizeGroupAndParents($groupId);

		if ($onlyCurrentGroup === true)
		{
			$primary = DB::table($roleTable)
						->join($userRoleTable, $roleTable . '.slug', '=', $userRoleTable . '.roles_slug')
						->select(DB::raw($roleTable . '.slug,' . $roleTable . '.name,' . $roleTable . '.description,' . $userRoleTable . '.group_id'))
						->where($userRoleTable . '.user_id', '=', $this->id)
						->where($userRoleTable . '.group_id', $groupId)
						->get();

			$custom = DB::table($roleCustomTable)
						->join($userRoleTable, $roleCustomTable . '.slug', '=', $userRoleTable . '.roles_slug')
						->select(DB::raw('slug, name, description, ' . $userRoleTable . '.group_id'))
						->where($userRoleTable . '.user_id', '=', $this->id)
						->where($userRoleTable . '.group_id', $groupId)
						->get();
		}
		else
		{
			$primary = DB::table($roleTable)
						->join($userRoleTable, $roleTable . '.slug', '=', $userRoleTable . '.roles_slug')
						->select(DB::raw($roleTable . '.slug,' . $roleTable . '.name,' . $roleTable . '.description,' . $userRoleTable . '.group_id'))
						->where($userRoleTable . '.user_id', '=', $this->id)
						->whereIn($userRoleTable . '.group_id', $groupAndParents)
						->get();

			$custom = DB::table($roleCustomTable)
						->join($userRoleTable, $roleCustomTable . '.slug', '=', $userRoleTable . '.roles_slug')
						->select(DB::raw('slug, name, description, ' . $userRoleTable . '.group_id'))
						->where($userRoleTable . '.user_id', '=', $this->id)
						->whereIn($userRoleTable . '.group_id', $groupAndParents)
						->get();
		}

		$data = array_merge($primary, $custom);

		$this->userRoles = array_map(function($v) {
			return new Role((array) $v);
		}, $data);

		return $this->userRoles;
	}

	/**
	 * Get the user's permissions. Valid filter values are :
	 *
	 * 'all' : get all permissions. This is the default
	 * 'role' : get only permissions that user has through a role, and are not explicit
	 * 'explicit' : get only the user's explicit permissions. These are permissions that have been directly set on the user.
	 *
	 * @param string $filter
	 *
	 * @return array
	 */
	public function getPermissions($filter = 'all')
	{
		$groupId = $this->getGroupId();

		// the permission cache contains all the user's permissions, and does not contain enough information
		// to execute the 'role' or 'explicit' filters.
		if ($filter == 'all' && !empty($this->userPermissions))
		{
			return $this->userPermissions;
		}

		$permissionTable = Config::get('can.permission_table');
		$userPermissionTable = Config::get('can.user_permission_table');

		$queryParams = [
			'joinKeyFirst' => $permissionTable.'.slug',
			'joinKeySecond' => $userPermissionTable.'.permissions_slug',
			'userIdKey' => $userPermissionTable.'.user_id',
			'userId' => $this->id,
		];

		$data = DB::table($permissionTable)
			->join($userPermissionTable, function($query) use($queryParams, $filter) {
				$query->on($queryParams['joinKeyFirst'], '=', $queryParams['joinKeySecond'])
					->where($queryParams['userIdKey'], '=', $queryParams['userId']);

				if($filter == 'role')
				{
					$query->where('added_on_user', false);
				} else if($filter == 'explicit') {
					$query->where('added_on_user', true);
				}
			})
			->get([$permissionTable.'.*']);

		$permissions = array_map(function($v) {
			return new Permission($v);
		}, $data);

		if($filter == 'all')
		{
			$this->userPermissions = $permissions;
		}

		return $permissions;
	}


	/**
	 * Returns the permissions associated with the provided role that are:
	 * a) not provided by any other role that is currently attached to the user in the group or any parent and
	 * b) have not been explicitly set on the user in the group or any parent
	 *
	 * @param $role
     * @param $groupId
	 *
	 * @return array
	 */
	private function uniquePermissionsForRole(Role $role, $groupId)
	{
		// 1) get role permissions
		$rolePermissions = $role->getPermissions($groupId);
		$rolePermissionSlugs = array_column($rolePermissions, 'slug');

		// 2) get user roles, excluding the provided role if it's there
		$userRoles = array_filter($this->getRoles($groupId), function($currRole) use($role) {
			return $currRole->slug !== $role->slug;
		});
		$userRoleSlugs = array_column($userRoles, 'slug');

		// 3) get all permissions associated with user roles above
		$rolePermissionTable = Config::get('can.role_permission_table');
        $otherRolePermissions = DB::table($rolePermissionTable)->whereIn('roles_slug', $userRoleSlugs)
            ->where('group_id', $groupId)->get();
		$otherRolePermissionSlugs = array_column($otherRolePermissions, 'permissions_slug');

		// 4) get all permissions that have been explicitly set on the user
		$explicitPermissions = DB::table(Config::get('can.user_permission_table'))->where('added_on_user', 1)
            ->where('group_id', $groupId)->get();
		$explicitPermissionSlugs = array_column($explicitPermissions, 'permissions_slug');

		// 5) all permission slugs not belonging to supplied permission
		$excludedPermissionSlugs = array_merge($otherRolePermissionSlugs, $explicitPermissionSlugs);

		// 6 diff
		$uniqueSlugs = array_diff($rolePermissionSlugs, $excludedPermissionSlugs);

		// 7 return objects corresponding to unique slugs
		$perms = array_filter($rolePermissions, function($o) use($uniqueSlugs) {
			return in_array($o->slug, $uniqueSlugs);
		});

		return $perms;
	}

	private function invalidateRoleCache()
	{
		$this->userRoles = null;
	}

	private function invalidatePermissionCache()
	{
		$this->userPermissions = null;
	}

	/**
	 * Return the group id. This is made for InteractiVid's own user/group middleware that gets stored in the session.
	 * However, we're leaving room open to make it flexible enough to work with other user/group implementations.
	 */
	protected function getGroupId()
	{
		if (isset($this->groupId))
			return $this->groupId;

		$this->groupId = 0;

		$userClass = Config::get('auth.providers.users.model');

		if (method_exists($userClass, 'getCurrentGroup'))
		{
			$group = $userClass::getCurrentGroup();
			$this->groupId = isset($group->id) ? $group->id : 0;
		}

		return $this->groupId;
	}


	/**
	 * Retrieve a single-level array of the group and its parents. This relies on the InteractiVid Group model.
	 * Nothing will be returned if this model doesn't exist.
	 * TODO: Bring the group management into interactivid/can?
	 */
	protected function normalizeGroupAndParents($groupId = null)
	{
		if ($groupId === null)
			$groupId = $this->getGroupId();

		// Check if we've already retrieved the normalized group and parents and stored it.
		if (isset($this->normalizedGroupAndParents[$groupId]))
			return $this->normalizedGroupAndParents[$groupId];

		$groupClass = 'Demovisor\Models\Group';
		$groupIds = [$groupId];
		$parents = [];
		if (method_exists($groupClass, 'normalizeParents'))
		{
			$group = $groupClass::where('id', $groupId)->first();
			if ($group)
				$parents = $group->normalizeParents();

			$this->normalizedGroupAndParents[$groupId] = array_merge($groupIds, array_keys($parents));
			return $this->normalizedGroupAndParents[$groupId];
		}
		return null;
	}

	protected function getRootGroup($groupId = null)
	{
		if ($groupId === null)
			$groupId = $this->getGroupId();

		// Check if we've already retrieved the root group and stored it.
		if (isset($this->rootGroup[$groupId]))
			return $this->rootGroup[$groupId];

		$groupClass = 'Demovisor\Models\Group';
		$groupIds = [$groupId];
		if (method_exists($groupClass, 'getRootGroup'))
		{
			$group = $groupClass::where('id', $groupId)->first();
			if ($group)
			{
				$this->rootGroup[$groupId] = $group->getRootGroup();
				return $this->rootGroup[$groupId];
			}
		}
		return null;
	}
}
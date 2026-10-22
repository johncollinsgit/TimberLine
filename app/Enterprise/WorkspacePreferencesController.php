<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class WorkspacePreferencesController
{
    public function show(Request $request, WorkspacePreferences $preferences)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid']]);

        return response()->json($preferences->show($request->attributes->get('current_tenant'), $request->user(), $input['area_id']))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, WorkspacePreferences $preferences)
    {
        $rules = ['area_id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'], 'scope' => ['required', 'in:personal,workspace'],
            'expected_version' => ['required', 'integer', 'min:0'], 'settings' => ['required', 'array:density,view,favorites,filter'],
            'settings.density' => ['sometimes', 'in:comfortable,compact'], 'settings.view' => ['sometimes', 'in:list,calendar,map'],
            'settings.favorites' => ['sometimes', 'array', 'max:30'], 'settings.favorites.*' => ['uuid', 'distinct'],
            'settings.filter' => ['sometimes', 'in:all,active,completed,mine'],
            'intake_published' => ['sometimes', 'boolean'], 'roles' => ['sometimes', 'array:manager,employee,viewer']];
        foreach (['manager', 'employee', 'viewer'] as $role) {
            $rules['roles.'.$role] = ['sometimes', 'array:density,view,filter'];
            $rules['roles.'.$role.'.density'] = ['sometimes', 'in:comfortable,compact'];
            $rules['roles.'.$role.'.view'] = ['sometimes', 'in:list,calendar,map'];
            $rules['roles.'.$role.'.filter'] = ['sometimes', 'in:all,active,completed,mine'];
        }
        $input = $request->validate($rules);
        $input['expected_version'] = (int) $input['expected_version'];
        if (array_key_exists('intake_published', $input)) {
            $input['intake_published'] = $request->boolean('intake_published');
        }

        return response()->json($preferences->save($request->attributes->get('current_tenant'), $request->user(), $input))->header('Cache-Control', 'private, no-store');
    }
}

<?php

namespace Pterodactyl\Http\Requests\Admin\Settings;

use Pterodactyl\Http\Requests\Admin\AdminFormRequest;

class ModpacksSettingsFormRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'pterodactyl:modpacks:curseforge:enabled' => 'required|in:true,false',
            'pterodactyl:modpacks:curseforge:api_key' => 'nullable|string|max:255',
            'pterodactyl:modpacks:modrinth:enabled' => 'required|in:true,false',
            'pterodactyl:modpacks:default_page_size' => 'required|integer|in:10,25,50,100',
        ];
    }

    public function attributes(): array
    {
        return [
            'pterodactyl:modpacks:curseforge:enabled' => 'CurseForge Enabled',
            'pterodactyl:modpacks:curseforge:api_key' => 'CurseForge API Key',
            'pterodactyl:modpacks:modrinth:enabled' => 'Modrinth Enabled',
            'pterodactyl:modpacks:default_page_size' => 'Default Page Size',
        ];
    }
}

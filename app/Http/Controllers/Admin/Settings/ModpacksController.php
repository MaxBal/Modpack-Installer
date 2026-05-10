<?php

namespace Pterodactyl\Http\Controllers\Admin\Settings;

use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Encryption\Encrypter;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Providers\SettingsServiceProvider;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;
use Pterodactyl\Http\Requests\Admin\Settings\ModpacksSettingsFormRequest;

class ModpacksController extends Controller
{
    public function __construct(
        private AlertsMessageBag $alert,
        private Kernel $kernel,
        private Encrypter $encrypter,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    public function index(): View
    {
        $apiKey = (string) config('pterodactyl.modpacks.curseforge.api_key', '');

        return view('admin.settings.modpacks', [
            'curseforgeApiKeyMask' => $apiKey !== '' ? str_repeat('*', max(strlen($apiKey) - 4, 0)) . substr($apiKey, -4) : null,
        ]);
    }

    public function update(ModpacksSettingsFormRequest $request): RedirectResponse
    {
        foreach ($request->normalize() as $key => $value) {
            if (in_array($key, SettingsServiceProvider::getEncryptedKeys()) && !empty($value)) {
                $value = $this->encrypter->encrypt($value);
            }

            if ($key === 'pterodactyl:modpacks:curseforge:api_key' && empty($value)) {
                continue;
            }

            $this->settings->set('settings::' . $key, $value);
        }

        $this->kernel->call('queue:restart');
        $this->alert->success('Modpack settings have been updated successfully.')->flash();

        return redirect()->route('admin.settings.modpacks');
    }
}

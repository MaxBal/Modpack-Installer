@extends('layouts.admin')
@include('partials/admin.settings.nav', ['activeTab' => 'modpacks'])

@section('title')
    Modpack Settings
@endsection

@section('content-header')
    <h1>Modpack Settings<small>Configure CurseForge and Modrinth integrations.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Settings</li>
    </ol>
@endsection

@section('content')
    @yield('settings::nav')
    <form action="{{ route('admin.settings.modpacks') }}" method="POST">
        <div class="row">
            <div class="col-xs-12">
                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Provider Settings</h3>
                    </div>
                    <div class="box-body">
                        <div class="form-group">
                            <label class="control-label">CurseForge</label>
                            <select class="form-control" name="pterodactyl:modpacks:curseforge:enabled">
                                <option value="true" @if(config('pterodactyl.modpacks.curseforge.enabled', true)) selected @endif>Enabled</option>
                                <option value="false" @if(!config('pterodactyl.modpacks.curseforge.enabled', true)) selected @endif>Disabled</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="control-label">CurseForge API Key</label>
                            <input type="password" name="pterodactyl:modpacks:curseforge:api_key" class="form-control" autocomplete="new-password" placeholder="{{ $curseforgeApiKeyMask ? 'Current key: ' . $curseforgeApiKeyMask : 'Paste API key' }}">
                            <p class="text-muted small">Leave blank to keep the current key. This key is stored globally and is never exposed to server users.</p>
                        </div>
                        <div class="form-group">
                            <label class="control-label">Modrinth</label>
                            <select class="form-control" name="pterodactyl:modpacks:modrinth:enabled">
                                <option value="true" @if(config('pterodactyl.modpacks.modrinth.enabled', true)) selected @endif>Enabled</option>
                                <option value="false" @if(!config('pterodactyl.modpacks.modrinth.enabled', true)) selected @endif>Disabled</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="control-label">Default Page Size</label>
                            <select class="form-control" name="pterodactyl:modpacks:default_page_size">
                                @foreach([10, 25, 50, 100] as $size)
                                    <option value="{{ $size }}" @if((int) config('pterodactyl.modpacks.default_page_size', 50) === $size) selected @endif>{{ $size }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="box-footer">
                        {!! csrf_field() !!}
                        {!! method_field('PATCH') !!}
                        <button type="submit" class="btn btn-sm btn-primary pull-right">Save</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection

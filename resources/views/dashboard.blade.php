@extends('slipstream::layout')

@section('title')
    @if(($page ?? '') === 'webhook') Webhook
    @elseif(($page ?? '') === 'settings') Settings
    @elseif(($page ?? '') === 'servers') Servers
    @elseif(($page ?? '') === 'pipelines') Pipelines
    @elseif(($page ?? '') === 'hooks') Deployment Hooks
    @elseif(($page ?? '') === 'environment') Environment
    @elseif(($page ?? '') === 'deployment') Deployment
    @else Deployments
    @endif
@endsection

@section('content')
    @if(($page ?? '') === 'deployments')
        @livewire('slipstream.deployments-index')
    @elseif(($page ?? '') === 'deployment')
        @livewire('slipstream.deployment-show', ['deploymentId' => $deploymentId])
    @elseif(($page ?? '') === 'servers')
        @livewire('slipstream.servers-page')
    @elseif(($page ?? '') === 'pipelines')
        @livewire('slipstream.pipelines-page')
    @elseif(($page ?? '') === 'hooks')
        @livewire('slipstream.deploy-hooks-page')
    @elseif(($page ?? '') === 'webhook')
        @livewire('slipstream.webhook-page')
    @elseif(($page ?? '') === 'settings')
        @livewire('slipstream.settings-page')
    @elseif(($page ?? '') === 'environment')
        @livewire('slipstream.environment-page')
    @endif
@endsection

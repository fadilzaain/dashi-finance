@props(['name', 'label', 'type' => 'text'])

<div class="field">
    <label for="{{ $name }}">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
           value="{{ $type === 'password' ? '' : old($name) }}"
           {{ $attributes->merge(['class' => 'input']) }}>
    @error($name)
        <p class="galat">{{ $message }}</p>
    @enderror
</div>
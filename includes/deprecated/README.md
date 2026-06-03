# Deprecated

This directory houses classes and functions that should no longer be used, but
which cannot yet be removed — typically because third-party code (other plugins,
themes, or custom integrations) is known to reference them in the wild.

## What belongs here

- A class or function that has no remaining callers within this plugin.
- A class or function that we are aware is referenced externally (for example,
  via `class_exists()` or direct method calls), such that outright removal would
  break those integrations on existing installs.

## Conventions

Every class or function placed here should:

1. Carry a `@deprecated` tag in its docblock, naming the version it was
   deprecated in and any recommended replacement.
2. Emit a deprecation notice when loaded, instantiated, or called. For classes,
   prefer a top-level `_deprecated_class()` call placed in the class file but
   _outside_ the class definition, so that a `class_exists( $name, true )` check
   triggers the notice even if the class is never instantiated.
3. Be reduced to a minimal shell where possible, as these classes exist to satisfy
   backwards-compatibility checks, not to do real work. Ideally, method bodies 
   should be empty (or no-ops), and side effects (e.g. registering autoloaders,
   scheduling actions) should be removed.

## Removal

Entries here should be revisited periodically. Once we are confident that no
extant integration still depends on a given symbol, it can be deleted from this
directory.

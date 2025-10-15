# The internal namespace

- All the code in this directory (and hence in the `Automattic\WooCommerce_Subscriptions\Internal` namespace) is internal WooCommerce Subscriptions infrastructure code.
- Our internal code can change at any time (there are zero guarantees of backwards compatibility), and so the classes and methods you see here should be avoided by extension developers.
- This guidance applies to all the code entities in this namespace, even those not having an `@internal` annotation.


PHP_ARG_ENABLE([pdo-atoms], [whether to enable the Atoms PDO driver],
  [AS_HELP_STRING([--enable-pdo-atoms], [Enable PDO access to the active Atom database])], [no])

if test "$PHP_PDO_ATOMS" != "no"; then
  PHP_NEW_EXTENSION([pdo_atoms], [pdo_atoms.c], [$ext_shared])
  PHP_ADD_EXTENSION_DEP([pdo_atoms], [pdo])
fi

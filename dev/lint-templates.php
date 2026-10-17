<?php
/** Standalone Twig syntax check; not a Drupal runtime or access test. */
require $argv[1] . '/vendor/autoload.php';
$twig = new Twig\Environment(new Twig\Loader\FilesystemLoader(__DIR__ . '/../templates'));
foreach (['t', 'render', 'clean_class'] as $name) {
  $twig->addFilter(new Twig\TwigFilter($name, static fn ($value) => $value));
}
$twig->addFunction(new Twig\TwigFunction('create_attribute', static fn () => []));
foreach (glob(__DIR__ . '/../templates/*.html.twig') as $file) {
  $twig->parse($twig->tokenize(new Twig\Source(file_get_contents($file), basename($file))));
  echo basename($file) . " syntax OK\n";
}

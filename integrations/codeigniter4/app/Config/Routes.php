<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Puzzles::new');
// Not 'puzzles': public/puzzles/ holds the built puzzles, and web servers
// (php spark serve, Apache's !-d rule) serve existing directories directly.
$routes->post('play', 'Puzzles::create');
$routes->get('play/(:segment)', 'Puzzles::show/$1');
$routes->get('play/(:segment)/progress', 'Puzzles::progress/$1');
$routes->post('play/(:segment)/progress', 'Puzzles::saveProgress/$1');

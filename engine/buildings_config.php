<?php
// Central building config for City (Travian-like). RC_01a: dependencies + limits only.
return [
  'main_building' => [
    'max_level' => 20,
    'multi' => false,
    'requires' => [],
  ],
  'warehouse' => [
    'max_level' => 20,
    'multi' => true,
    'requires' => ['main_building'=>1],
  ],
  'granary' => [
    'max_level' => 20,
    'multi' => true,
    'requires' => ['main_building'=>1],
  ],
  'hideout' => [
    'max_level' => 20,
    'multi' => true,
    'requires' => ['main_building'=>1],
  ],
  'rally_point' => [
    'max_level' => 20,
    'multi' => false,
    'requires' => ['main_building'=>1],
  ],
  'barracks' => [
    'max_level' => 20,
    'multi' => false,
    'requires' => ['rally_point'=>1, 'main_building'=>3],
  ],
  'stable' => [
    'max_level' => 20,
    'multi' => false,
    'requires' => ['academy'=>5, 'barracks'=>5],
  ],
  'workshop' => [
    'max_level' => 20,
    'multi' => false,
    'requires' => ['academy'=>10, 'main_building'=>5],
  ],
  'academy' => [
    'max_level' => 20,
    'multi' => false,
    'requires' => ['main_building'=>3, 'barracks'=>3],
  ],
  'smithy' => [
    'max_level' => 20,
    'multi' => false,
    'requires' => ['main_building'=>3, 'academy'=>1],
  ],
  'armoury' => [
    'max_level' => 20,
    'multi' => false,
    'requires' => ['main_building'=>3, 'smithy'=>1],
  ],
  'market' => [
    'max_level' => 20,
    'multi' => false,
    'requires' => ['main_building'=>3, 'warehouse'=>1, 'granary'=>1],
  ],
  'residence' => [
    'max_level' => 20,
    'multi' => false,
    'requires' => ['main_building'=>5],
  ],
  'palace' => [
    'max_level' => 20,
    'multi' => false,
    'requires' => ['main_building'=>5],
  ],
  'town_hall' => [
    'max_level' => 20,
    'multi' => false,
    'requires' => ['main_building'=>10, 'academy'=>10],
  ],
  'wall' => [
    'max_level' => 20,
    'multi' => false,
    'requires' => ['main_building'=>1],
  ],

  // Level-5 boosters
  'grain_mill' => [
    'max_level' => 5,
    'multi' => false,
    'requires' => ['main_building'=>5],
  ],
  'bakery' => [
    'max_level' => 5,
    'multi' => false,
    'requires' => ['grain_mill'=>5, 'main_building'=>5],
  ],
  'sawmill' => [
    'max_level' => 5,
    'multi' => false,
    'requires' => ['main_building'=>5],
  ],
  'brickyard' => [
    'max_level' => 5,
    'multi' => false,
    'requires' => ['main_building'=>5],
  ],
  'iron_foundry' => [
    'max_level' => 5,
    'multi' => false,
    'requires' => ['main_building'=>5],
  ],

  // Specials
  'treasury' => [
    'max_level' => 20,
    'multi' => false,
    'requires' => ['main_building'=>10],
  ],
  'great_warehouse' => [
    'max_level' => 20,
    'multi' => false,
    'requires' => ['warehouse'=>20],
  ],
  'great_granary' => [
    'max_level' => 20,
    'multi' => false,
    'requires' => ['granary'=>20],
  ],
  'great_barracks' => [
    'max_level' => 20,
    'multi' => false,
    'requires' => ['barracks'=>20, 'main_building'=>20],
  ],
  'great_stable' => [
    'max_level' => 20,
    'multi' => false,
    'requires' => ['stable'=>20, 'main_building'=>20],
  ],

  // Tribe special (enable later based on tribe)
  'trapper' => [
    'max_level' => 20,
    'multi' => true,
    'requires' => ['main_building'=>1],
  ],
];

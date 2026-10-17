<?php

declare(strict_types=1);

// Included by hook_theme(); component values remain safe render arrays.
return [
  'actools_lesson_reader' => [
    'variables' => [
      'title' => [], 'summary' => [], 'introduction' => [], 'chapter_link' => [], 'chapter_return' => [],
      'chapter_items' => [], 'lesson_position' => '', 'mode' => 'guided',
      'learning_path_label' => [], 'chapter_navigation_label' => 'Chapter lessons',
      'all_url' => '', 'guided_url' => '', 'print_url' => '', 'mode_links' => [],
      'steps' => [], 'current_step_title' => [], 'current_step_number' => 0,
      'total_steps' => 0, 'required_steps' => 0, 'steps_content' => [],
      'previous_step' => [], 'next_step' => [], 'previous_lesson' => [],
      'next_lesson' => [], 'progress' => [], 'objectives' => [],
      'prerequisites' => [], 'preview' => FALSE, 'status' => [], 'lesson_id' => NULL,
    ],
    'template' => 'actools-lesson-reader',
  ],
  'actools_lesson_step' => [
    'variables' => [
      'id' => '', 'title' => [], 'number' => 0, 'content' => [],
      'optional' => FALSE, 'reviewed' => FALSE, 'preview' => FALSE,
    ],
    'template' => 'actools-lesson-step',
  ],
  'actools_lesson_component' => [
    'variables' => [
      'id' => '', 'component_type' => 'text', 'heading' => [], 'body' => [], 'hint' => [],
      'answer' => [], 'solution' => [], 'tex' => '', 'figure' => [],
      'caption' => [], 'description' => [], 'source' => [],
      'equation_number' => [], 'expanded' => FALSE,
    ],
    'template' => 'actools-lesson-component',
  ],
  'actools_lesson_chapter' => [
    'variables' => [
      'title' => [], 'code' => '', 'introduction' => [],
      'objectives' => [], 'prerequisites' => [], 'lessons' => [],
      'start_link' => [], 'continue_progress' => [], 'resources' => [],
      'preview' => FALSE,
    ],
    'template' => 'actools-lesson-chapter',
  ],
];

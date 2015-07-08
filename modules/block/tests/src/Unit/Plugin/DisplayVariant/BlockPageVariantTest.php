<?php

/**
 * @file
 * Contains \Drupal\Tests\block\Unit\Plugin\DisplayVariant\BlockPageVariantTest.
 */

namespace Drupal\Tests\block\Unit\Plugin\DisplayVariant;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\Container;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\block\Plugin\DisplayVariant\BlockPageVariant
 * @group block
 */
class BlockPageVariantTest extends UnitTestCase {

  /**
   * The block repository.
   *
   * @var \Drupal\block\BlockRepositoryInterface|\PHPUnit_Framework_MockObject_MockObject
   */
  protected $blockRepository;

  /**
   * The block view builder.
   *
   * @var \Drupal\Core\Entity\EntityViewBuilderInterface|\PHPUnit_Framework_MockObject_MockObject
   */
  protected $blockViewBuilder;

  /**
   * The plugin context handler.
   *
   * @var \Drupal\Core\Plugin\Context\ContextHandlerInterface|\PHPUnit_Framework_MockObject_MockObject
   */
  protected $contextHandler;

  /**
   * Sets up a display variant plugin for testing.
   *
   * @param array $configuration
   *   An array of plugin configuration.
   * @param array $definition
   *   The plugin definition array.
   *
   * @return \Drupal\block\Plugin\DisplayVariant\BlockPageVariant|\PHPUnit_Framework_MockObject_MockObject
   *   A mocked display variant plugin.
   */
  public function setUpDisplayVariant($configuration = array(), $definition = array()) {

    $container = new Container();
    $cache_context_manager = $this->getMockBuilder('Drupal\Core\Cache\CacheContextsManager')
      ->disableOriginalConstructor()
      ->getMock();
    $container->set('cache_contexts_manager', $cache_context_manager);
    $cache_context_manager->expects($this->any())
      ->method('validateTokens')
      ->with([])
      ->willReturn([]);
    \Drupal::setContainer($container);

    $this->blockRepository = $this->getMock('Drupal\block\BlockRepositoryInterface');
    $this->blockViewBuilder = $this->getMock('Drupal\Core\Entity\EntityViewBuilderInterface');

    return $this->getMockBuilder('Drupal\block\Plugin\DisplayVariant\BlockPageVariant')
      ->setConstructorArgs(array($configuration, 'test', $definition, $this->blockRepository, $this->blockViewBuilder, ['config:block_list']))
      ->setMethods(array('getRegionNames'))
      ->getMock();
  }

  public function providerBuild() {
    $blocks_config = array(
      'block1' => array(
        // region, is main content block, is messages block
        'top', FALSE, FALSE,
      ),
      // Test multiple blocks in the same region.
      'block2' => array(
        'bottom', FALSE, FALSE,
      ),
      'block3' => array(
        'bottom', FALSE, FALSE,
      ),
      // Test a block implementing MainContentBlockPluginInterface.
      'block4' => array(
        'center', TRUE, FALSE,
      ),
      // Test a block implementing MessagesBlockPluginInterface.
      'block5' => array(
        'center', FALSE, TRUE,
      ),
    );

    $test_cases = [];
    $test_cases[] = [$blocks_config, 5,
      [
        '#cache' => [
          'tags' => [
            'config:block_list',
            'route',
          ],
          'contexts' => [],
          'max-age' => -1,
        ],
        'top' => [
          'block1' => [],
          '#sorted' => TRUE,
        ],
        // The main content was rendered via a block.
        'center' => [
          'block4' => [],
          'block5' => [],
          '#sorted' => TRUE,
        ],
        'bottom' => [
          'block2' => [],
          'block3' => [],
          '#sorted' => TRUE,
        ],
      ],
    ];
    unset($blocks_config['block5']);
    $test_cases[] = [$blocks_config, 4,
      [
        '#cache' => [
          'tags' => [
            'config:block_list',
            'route',
          ],
          'contexts' => [],
          'max-age' => -1,
        ],
        'top' => [
          'block1' => [],
          '#sorted' => TRUE,
        ],
        'center' => [
          'block4' => [],
          '#sorted' => TRUE,
        ],
        'bottom' => [
          'block2' => [],
          'block3' => [],
          '#sorted' => TRUE,
        ],
        // The messages are rendered via the fallback in case there is no block
        // rendering the main content.
        'content' => [
          'messages' => [
            '#weight' => -1000,
            '#type' => 'status_messages',
          ],
        ],
      ],
    ];
    unset($blocks_config['block4']);
    $test_cases[] = [$blocks_config, 3,
      [
        '#cache' => [
          'tags' => [
            'config:block_list',
            'route',
          ],
          'contexts' => [],
          'max-age' => -1,
        ],
        'top' => [
          'block1' => [],
          '#sorted' => TRUE,
        ],
        'bottom' => [
          'block2' => [],
          'block3' => [],
          '#sorted' => TRUE,
        ],
        // The main content & messages are rendered via the fallback in case
        // there are no blocks rendering them.
        'content' => [
          'system_main' => ['#markup' => 'Hello kittens!'],
          'messages' => [
            '#weight' => -1000,
            '#type' => 'status_messages',
          ],
        ],
      ],
    ];
    return $test_cases;
  }

  /**
   * Tests the building of a full page variant.
   *
   * @covers ::build
   *
   * @dataProvider providerBuild
   */
  public function testBuild(array $blocks_config, $visible_block_count, array $expected_render_array) {
    $display_variant = $this->setUpDisplayVariant();
    $display_variant->setMainContent(['#markup' => 'Hello kittens!']);

    $blocks = ['top' => [], 'center' => [], 'bottom' => []];
    $block_plugin = $this->getMock('Drupal\Core\Block\BlockPluginInterface');
    $main_content_block_plugin = $this->getMock('Drupal\Core\Block\MainContentBlockPluginInterface');
    $messages_block_plugin = $this->getMock('Drupal\Core\Block\MessagesBlockPluginInterface');
    foreach ($blocks_config as $block_id => $block_config) {
      $block = $this->getMock('Drupal\block\BlockInterface');
      $block->expects($this->any())
        ->method('getContexts')
        ->willReturn([]);
      $block->expects($this->atLeastOnce())
        ->method('getPlugin')
        ->willReturn($block_config[1] ? $main_content_block_plugin : ($block_config[2] ? $messages_block_plugin : $block_plugin));
      $blocks[$block_config[0]][$block_id] = $block;
    }
    $this->blockViewBuilder->expects($this->exactly($visible_block_count))
      ->method('view')
      ->will($this->returnValue(array()));
    $this->blockRepository->expects($this->once())
      ->method('getVisibleBlocksPerRegion')
      ->willReturnCallback(function (&$cacheable_metadata) use ($blocks) {
        $cacheable_metadata['top'] = (new CacheableMetadata())->addCacheTags(['route']);
        return $blocks;
      });

    $value = $display_variant->build();
    $this->assertSame($expected_render_array, $value);
  }

  /**
   * Tests the building of a full page variant with no main content set.
   *
   * @covers ::build
   */
  public function testBuildWithoutMainContent() {
    $display_variant = $this->setUpDisplayVariant();
    $this->blockRepository->expects($this->once())
      ->method('getVisibleBlocksPerRegion')
      ->willReturn([]);

    $expected = [
      '#cache' => [
        'tags' => [
          'config:block_list',
        ],
        'contexts' => [],
        'max-age' => -1,
      ],
      'content' => [
        'system_main' => [],
        'messages' => [
          '#weight' => -1000,
          '#type' => 'status_messages',
        ],
      ],
    ];
    $this->assertSame($expected, $display_variant->build());
  }

}

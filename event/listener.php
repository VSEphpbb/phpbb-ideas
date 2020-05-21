<?php
/**
 *
 * Ideas extension for the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\ideas\event;

use phpbb\auth\auth;
use phpbb\config\config;
use phpbb\controller\helper;
use phpbb\ideas\factory\ideas;
use phpbb\ideas\factory\linkhelper;
use phpbb\language\language;
use phpbb\template\template;
use phpbb\user;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
	/** @var auth */
	protected $auth;

	/* @var config */
	protected $config;

	/* @var helper */
	protected $helper;

	/* @var ideas */
	protected $ideas;

	/** @var language */
	protected $language;

	/* @var linkhelper */
	protected $link_helper;

	/* @var template */
	protected $template;

	/* @var user */
	protected $user;

	/** @var string */
	protected $php_ext;

	/**
	 * @param \phpbb\auth\auth                $auth
	 * @param \phpbb\config\config            $config
	 * @param \phpbb\controller\helper        $helper
	 * @param \phpbb\ideas\factory\ideas      $ideas
	 * @param \phpbb\language\language        $language
	 * @param \phpbb\ideas\factory\linkhelper $link_helper
	 * @param \phpbb\template\template        $template
	 * @param \phpbb\user                     $user
	 * @param string                          $php_ext
	 */
	public function __construct(auth $auth, config $config, helper $helper, ideas $ideas, language $language, linkhelper $link_helper, template $template, user $user, $php_ext)
	{
		$this->auth = $auth;
		$this->config = $config;
		$this->helper = $helper;
		$this->ideas = $ideas;
		$this->language = $language;
		$this->link_helper = $link_helper;
		$this->template = $template;
		$this->user = $user;
		$this->php_ext = $php_ext;

		$this->language->add_lang('common', 'phpbb/ideas');
	}

	/**
	 * @inheritDoc
	 */
	public static function getSubscribedEvents()
	{
		return array(
			'core.viewforum_get_topic_data'				=> 'ideas_forum_redirect',
			'core.viewtopic_modify_post_row'			=> array(array('clean_message'), array('show_post_buttons')),
			'core.viewtopic_modify_page_title'			=> 'show_idea',
			'core.viewtopic_add_quickmod_option_before'	=> 'adjust_quickmod_tools',
			'core.viewonline_overwrite_location'		=> 'viewonline_ideas',
			'core.posting_modify_submit_post_after'		=> 'edit_idea_title',
		);
	}

	/**
	 * Redirect users from the forum to the Ideas centre
	 *
	 * @param \phpbb\event\data $event The event object
	 * @return void
	 * @access public
	 */
	public function ideas_forum_redirect($event)
	{
		if ($this->is_ideas_forum($event['forum_id']))
		{
			// Use the custom base url if set, otherwise default to normal routing
			$url = $this->config['ideas_base_url'] ?: $this->helper->route('phpbb_ideas_index_controller');
			redirect($url);
		}
	}

	/**
	 * Clean obsolete link-backs from idea topics posted prior to Sep. 2017
	 *
	 * @param \phpbb\event\data $event The event object
	 * @return void
	 * @access public
	 */
	public function clean_message($event)
	{
		if (!$this->is_ideas_forum($event['row']['forum_id']))
		{
			return;
		}

		if ($this->is_first_post($event['topic_data']['topic_first_post_id'], $event['row']['post_id']) && $event['topic_data']['topic_time'] < strtotime('September 1, 2017'))
		{
			// This freakish looking regex pattern should remove the old ideas link-backs from the message.
			$event->update_subarray(
				'post_row',
				'MESSAGE',
				preg_replace('/(<br[^>]*>\\n?)?\\1?-{10}\\1?\\1?(?:(?!<\/[rt]>).)*/s', '', $event['post_row']['MESSAGE'])
			);
		}
	}

	/**
	 * Show post buttons (hide delete, quote or warn user buttons)
	 *
	 * @param \phpbb\event\data $event The event object
	 * @return void
	 * @access public
	 */
	public function show_post_buttons($event)
	{
		if (!$this->is_ideas_forum($event['row']['forum_id']))
		{
			return;
		}

		if ($event['topic_data']['topic_first_post_id'] == $event['row']['post_id'])
		{
			$event->update_subarray('post_row', 'U_DELETE', false);
			$event->update_subarray('post_row', 'U_WARN', false);
		}
	}

	/**
	 * Show the idea related to the current topic
	 *
	 * @param \phpbb\event\data $event The event object
	 * @return void
	 * @access public
	 */
	public function show_idea($event)
	{
		if (!$this->is_ideas_forum($event['forum_id']))
		{
			return;
		}

		$idea = $this->ideas->get_idea_by_topic_id($event['topic_data']['topic_id']);

		if (!$idea)
		{
			return;
		}

		$mod = $this->auth->acl_get('m_', (int) $this->config['ideas_forum_id']);
		$own = $idea['idea_author'] === $this->user->data['user_id'];

		if ($mod)
		{
			$this->template->assign_var('STATUS_ARY', ideas::$statuses);

			// Add quick mod option for deleting an idea
			$this->template->alter_block_array('quickmod', array(
				'VALUE'		=> 'delete_topic', // delete topic is used here simply to enable ajax
				'TITLE'		=> $this->language->lang('DELETE_IDEA'),
				'LINK'		=> $this->link_helper->get_idea_link($idea['idea_id'], 'delete'),
			));
		}

		$points = $idea['idea_votes_up'] - $idea['idea_votes_down'];
		$can_vote = (bool) ($idea['idea_status'] != ideas::$statuses['IMPLEMENTED'] &&
			$idea['idea_status'] != ideas::$statuses['DUPLICATE'] &&
			$this->auth->acl_get('f_vote', (int) $this->config['ideas_forum_id']) &&
			$event['topic_data']['topic_status'] != ITEM_LOCKED);

		$s_voted_up = $s_voted_down = false;
		if ($idea['idea_votes_up'] || $idea['idea_votes_down'])
		{
			$votes = $this->ideas->get_voters($idea['idea_id']);

			foreach ($votes as $vote)
			{
				$this->template->assign_block_vars('votes_' . ($vote['vote_value'] ? 'up' : 'down'), array(
					'USER' => $vote['user'],
				));

				if ($this->user->data['user_id'] == $vote['user_id'])
				{
					$s_voted_up = ((int) $vote['vote_value'] === 1);
					$s_voted_down = ((int) $vote['vote_value'] === 0);
				}
			}
		}

		$this->template->assign_vars(array(
			'IDEA_ID'			=> $idea['idea_id'],
			'IDEA_TITLE'		=> $idea['idea_title'],
			'IDEA_AUTHOR'		=> $this->link_helper->get_user_link($idea['idea_author']),
			'IDEA_DATE'			=> $this->user->format_date($idea['idea_date']),
			'IDEA_VOTES'		=> $idea['idea_votes_up'] + $idea['idea_votes_down'],
			'IDEA_VOTES_UP'		=> $idea['idea_votes_up'],
			'IDEA_VOTES_DOWN'	=> $idea['idea_votes_down'],
			'IDEA_POINTS'		=> $points,
			'IDEA_STATUS_ID'	=> $idea['idea_status'],
			'IDEA_STATUS_NAME'	=> $this->ideas->get_status_from_id($idea['idea_status']),

			'IDEA_DUPLICATE'	=> $idea['duplicate_id'] ? $this->ideas->get_title($idea['duplicate_id']) : '',
			'IDEA_RFC'			=> $idea['rfc_link'],
			'IDEA_TICKET'		=> $idea['ticket_id'],
			'IDEA_IMPLEMENTED'	=> $idea['implemented_version'],

			'S_IS_MOD'			=> $mod,
			'S_CAN_EDIT'		=> $mod || $own,
			'S_CAN_VOTE'		=> $can_vote,
			'S_CAN_VOTE_UP'		=> $can_vote && !$s_voted_up,
			'S_CAN_VOTE_DOWN'	=> $can_vote && !$s_voted_down,
			'S_VOTED'			=> $s_voted_up || $s_voted_down,
			'S_VOTED_UP'		=> $s_voted_up,
			'S_VOTED_DOWN'		=> $s_voted_down,

			'U_CHANGE_STATUS'	=> $this->link_helper->get_idea_link($idea['idea_id'], 'status', true),
			'U_EDIT_DUPLICATE'	=> $this->link_helper->get_idea_link($idea['idea_id'], 'duplicate', true),
			'U_EDIT_RFC'		=> $this->link_helper->get_idea_link($idea['idea_id'], 'rfc', true),
			'U_EDIT_IMPLEMENTED'=> $this->link_helper->get_idea_link($idea['idea_id'], 'implemented', true),
			'U_EDIT_TICKET'		=> $this->link_helper->get_idea_link($idea['idea_id'], 'ticket', true),
			'U_REMOVE_VOTE'		=> $this->link_helper->get_idea_link($idea['idea_id'], 'removevote', true),
			'U_IDEA_VOTE'		=> $this->link_helper->get_idea_link($idea['idea_id'], 'vote', true),
			'U_IDEA_DUPLICATE'	=> $this->link_helper->get_idea_link($idea['duplicate_id']),
			'U_IDEA_STATUS_LINK'=> $this->helper->route('phpbb_ideas_list_controller', array('status' => $idea['idea_status'])),
			'U_TITLE_LIVESEARCH'=> $this->helper->route('phpbb_ideas_livesearch_controller'),
		));

		// Use Ideas breadcrumbs
		$this->template->destroy_block_vars('navlinks');
		$this->template->assign_block_vars('navlinks', array(
			'U_VIEW_FORUM'		=> $this->helper->route('phpbb_ideas_index_controller'),
			'FORUM_NAME'		=> $this->language->lang('IDEAS'),
		));
	}

	/**
	 * Adjust the QuickMod tools displayed
	 * (hide options to delete, restore, make global, sticky or announcement)
	 *
	 * @param \phpbb\event\data $event The event object
	 * @return void
	 * @access public
	 */
	public function adjust_quickmod_tools($event)
	{
		if (!$this->is_ideas_forum($event['forum_id']))
		{
			return;
		}

		$quickmod_array = $event['quickmod_array'];

		//$quickmod_array['lock'][1] = false;
		//$quickmod_array['unlock'][1] = false;
		$quickmod_array['delete_topic'][1] = false;
		$quickmod_array['restore_topic'][1] = false;
		//$quickmod_array['move'][1] = false;
		//$quickmod_array['split'][1] = false;
		//$quickmod_array['merge'][1] = false;
		//$quickmod_array['merge_topic'][1] = false;
		//$quickmod_array['fork'][1] = false;
		$quickmod_array['make_normal'][1] = false;
		$quickmod_array['make_sticky'][1] = false;
		$quickmod_array['make_announce'][1] = false;
		$quickmod_array['make_global'][1] = false;

		$event['quickmod_array'] = $quickmod_array;
	}

	/**
	 * Show users as viewing Ideas on Who Is Online page
	 *
	 * @param \phpbb\event\data $event The event object
	 * @return void
	 * @access public
	 */
	public function viewonline_ideas($event)
	{
		if ($event['on_page'][1] === 'app')
		{
			if (strrpos($event['row']['session_page'], 'app.' . $this->php_ext . '/ideas/post') === 0)
			{
				$event['location'] = $this->language->lang('POSTING_NEW_IDEA');
				$event['location_url'] = $this->helper->route('phpbb_ideas_index_controller');
			}
			else if (strrpos($event['row']['session_page'], 'app.' . $this->php_ext . '/ideas') === 0)
			{
				$event['location'] = $this->language->lang('VIEWING_IDEAS');
				$event['location_url'] = $this->helper->route('phpbb_ideas_index_controller');
			}
		}
		else if ($event['on_page'][1] === 'viewtopic' && $event['row']['session_forum_id'] == $this->config['ideas_forum_id'])
		{
			$event['location'] = $this->language->lang('VIEWING_IDEAS');
			$event['location_url'] = $this->helper->route('phpbb_ideas_index_controller');
		}
	}

	/**
	 * Update the idea's title when post title is edited.
	 *
	 * @param \phpbb\event\data $event The event object
	 * @return void
	 * @access public
	 */
	public function edit_idea_title($event)
	{
		if ($event['mode'] !== 'edit' ||
			$event['post_data']['topic_first_post_id'] != $event['post_id'] ||
			!$event['update_subject'] ||
			!$this->is_ideas_forum($event['forum_id']))
		{
			return;
		}

		$idea = $this->ideas->get_idea_by_topic_id($event['topic_id']);
		$this->ideas->set_title($idea['idea_id'], $event['post_data']['post_subject']);
	}

	/**
	 * Check if forum id is for the ideas the forum
	 *
	 * @param int $forum_id
	 * @return bool
	 * @access public
	 */
	protected function is_ideas_forum($forum_id)
	{
		return (int) $forum_id === (int) $this->config['ideas_forum_id'];
	}

	/**
	 * Check if a post is the first post in a topic
	 *
	 * @param int|string $topic_first_post_id
	 * @param int|string $post_id
	 * @return bool
	 * @access protected
	 */
	protected function is_first_post($topic_first_post_id, $post_id)
	{
		return (int) $topic_first_post_id === (int) $post_id;
	}
}

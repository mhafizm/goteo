<?php
/*
 * This file is part of the Goteo Package.
 *
 * (c) Platoniq y Fundación Goteo <fundacion@goteo.org>
 *
 * For the full copyright and license information, please view the README.md
 * and LICENSE files that was distributed with this source code.
 */

namespace Goteo\Controller;

use Goteo\Application\Message;
use Goteo\Application\AppEvents;
use Goteo\Application\Event\FilterProjectEvent;
use Goteo\Application\Config;
use Goteo\Application\Lang;
use Goteo\Application\Session;
use Goteo\Application\View;
use Goteo\Model\Page;
use Goteo\Library\Text;
use Goteo\Library\Worth;
use Goteo\Model\Message as SupportMessage;
use Goteo\Model\Project;
use Goteo\Model\Project\Account;
use Goteo\Model\Project\ProjectLocation;
use Goteo\Model\Invest;
use Goteo\Model\Project\Favourite;
use Goteo\Model\Project\Conf;
use Goteo\Model\Project\ProjectMilestone;
use Goteo\Model\Project\Category;
use Goteo\Model\License;
use Goteo\Model\SocialCommitment;
use Goteo\Model\Blog;
use Goteo\Model\Blog\Post as BlogPost;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Goteo\Controller\Dashboard\ProjectDashboardController;
use Spipu\Html2Pdf\Html2Pdf;
use Spipu\Html2Pdf\Exception\Html2PdfException;


class ProjectController extends \Goteo\Core\Controller {

    public function __construct() {
        // Cache & replica read activated in this controller
        $this->dbReplica(true);
        $this->dbCache(true);
        //Set the responsive theme
        View::setTheme('responsive');
    }

	public function indexAction($pid = null, $show = 'home', $post = null, Request $request) {

		if ($pid !== null) {
			return $this->view($pid, $show, $post, $request);
		}
		if ($request->query->has('create')) {
			return new RedirectResponse('/project/create');
		}
		return new RedirectResponse('/discover');
	}

	/**
	 * Initial create project action
	 * @param  Request $request [description]
	 * @return [type]           [description]
	 */
	public function createAction(Request $request) {

        if (!Session::isLogged()) {
            Message::info(Text::get('user-login-required-to_create'));
            return $this->redirect('/user/login?return='.urldecode('/project/create'));
        }

        if ($request->isMethod('post')) {

        	$social_commitment= strip_tags($request->request->get('social'));

            $data=[
                'name'         =>  strip_tags($request->request->get('name')),
                'subtitle'   =>  strip_tags($request->request->get('subtitle')),
                'social_commitment'   => $social_commitment,
                'social_description' =>  strip_tags($request->request->get('social-description')),
                'location'          =>  $request->request->get('location'),
                'project_location'			=>  $request->request->get('project_location')
            ];

            $project = Project::createNewProject($data);

            // categories created depending on the social commitment
        	$categories=SocialCommitment::getCategories($social_commitment);

        	foreach ($categories as $item) {
        		$category=new Category();
        		$category->project=$project->id;
        		$category->id=$item;
        		$category->save();
        	}

        	// Save location

        	$loc = new ProjectLocation(
        			[
                    'id'         => $project->id,
                    'city'         => $request->request->get('city'),
                    'region'       => $request->request->get('region'),
                    'country'      => $request->request->get('country'),
                    'country_code' => $request->request->get('country_code'),
                    'longitude'    => $request->request->get('longitude'),
                    'latitude'     => $request->request->get('latitude'),
                    'method'       => 'manual'
                ]
            );

            $loc->save($errors);

            // Save publishing day and min required estimation
            $conf = Project\Conf::get($project->id);
            $conf->mincost_estimation = $request->request->get('minimum');
            $conf->publishing_estimation = $request->request->get('publishing_date');
            $conf->save();


            // Save default fee
            $accounts = new Account();
            $accounts->project = $project->id;
            $accounts->allowpp = false;
            $accounts->fee = Config::get('fee');
            $accounts->save();


            // CREATED EVENT
            $response = $this->dispatch(AppEvents::PROJECT_CREATED, new FilterProjectEvent($project))->getResponse();
            if($response instanceOf Response) return $response;

            return new RedirectResponse('/dashboard/project/' . $project->id . '/profile');
        }

        return $this->viewResponse( 'project/create', [
           'social_commitments' => SocialCommitment::getAll(),
           'terms'      => Page::get('howto')
        ]);

	}

	protected function view($project, $show, $post = null, Request $request) {
		//activamos la cache para esta llamada
		\Goteo\Core\DB::cache(true);

		if( !$project instanceOf Project ) {
            $project = Project::get($project, Lang::current(false));
        }
		$user    = Session::getUser();

		$show_allow=['home', 'updates', 'participate'];

		if(!in_array($show, $show_allow))
			return $this->redirect('/project/' . $project->id);

        if ($project->node != Config::get('node'))
            $related_projects = Project::published([], $project->node, 0, 3);
        else
            $related_projects = Project::published(['categories' => $project->categories], null, 0, 3);

		$lsuf = (LANG != 'es') ? '?lang='.LANG : '';

        $URL = '//'.$request->getHttpHost();

        //Get widgets code

        $url = $URL . '/widget/project/' . $project->id;

        $widget_code = Text::widget($url . $lsuf);

        // mensaje cuando, sin estar en campaña, tiene fecha de publicación
        if (!$project->isApproved()) {
            if (!empty($project->published)) {
                if ($project->published >= date('Y-m-d')) {
                    // si la fecha es en el futuro, es que se publicará
                    Message::info(Text::get('project-willpublish', date('d/m/Y', strtotime($project->published))));
                } else {
                    // si la fecha es en el pasado, es que la campaña ha sido cancelada
                    Message::info(Text::get('project-unpublished'));
                }
            } else {
                // mensaje de no publicado siempre que no esté en campaña
                Message::info(Text::get('project-not_published'));
            }
        }

        // si lo puede ver
        if ($project->userCanView(Session::getUser())) {

            ProjectDashboardController::createProjectSidebar($project, 'preview');

            $project->cat_names = Project\Category::getNames($project->id);

            if ($show == 'home') {
                // para el widget embed
                $project->rewards = array_merge($project->social_rewards, $project->individual_rewards);
            }

            // Add analytics to config
            // TODO: do the same with facebook pixel (not done yet because f.pixel is only used in the project page)
            if($project->analytics_id) {
                Config::set('analytics.google', array_merge(Config::get('analytics.google'), [$project->analytics_id]));
            }

            $viewData = array(
                'project' => $project,
                'show'    => $show,
                'blog'    => null,
                'related_projects' => $related_projects,
                'widget_code' => $widget_code
            );

            $viewData['matchers'] = $project->getMatchers('active');

            // recompensas
            $viewData['individual_rewards'] = [];
            foreach ($project->getIndividualRewards(Lang::current(false)) as $reward) {

                //check if show the exhausted rewards
                if($reward->available()||!$project::hideExhaustedRewards($project->id)||!$project->inCampaign())
                {

                    $reward->none  = false;
                    $reward->taken = $reward->getTaken();// cofinanciadores quehan optado por esta recompensas
                    // si controla unidades de esta recompensa, mirar si quedan
                    if ($reward->units > 0 && $reward->taken >= $reward->units) {
                        $reward->none = true;
                    }
                    $viewData['individual_rewards'][] = $reward;
                }

            }

            // retornos adicionales (bonus)
            $viewData['bonus_rewards'] = [];
            $viewData['social_rewards'] = [];
            foreach ($project->getSocialRewards() as $reward) {
                if($reward->url && stripos($reward->url, 'http') !== 0) {
                    $reward->url = 'http://' .  $reward->url;
                }
                if ($reward->bonus) {
                    $viewData['bonus_rewards'][] = $reward;
                } else {
                    $viewData['social_rewards'][] = $reward;
                }
            }

            // Custom view data

            if ($show == 'home') {

                $viewData['types'] = Project\Cost::types();

                // Costs by type
                $costs = array();

                foreach ($project->costs as $cost) {
                    $costs[$cost->type][] = (object) array(
                        'name' => $cost->cost,
                        'description' => $cost->description,
                        'min' => $cost->required == 1 ? $cost->amount : '',
                        'opt' => $cost->amount,
                        'req' => $cost->required
                    );
                }

                $viewData['costs'] = $costs;

                // Licenses for the social rewards

                $licenses = array();

                foreach (License::getAll() as $l) {
                    $licenses[$l->id] = $l;
                }


                $viewData['licenses'] = $licenses;
            }

            // tenemos que tocar esto un poquito para motrar las necesitades no economicas
            if ($show == 'needs-non') {
                $viewData['show']         = 'needs';
                $viewData['non_economic'] = true;
            }

            // posts
            if ($show == 'updates') {

                //if is an individual post page
                if($post) {
                    $pob = BlogPost::getBySlug($post, Lang::current(), $project->lang);
                    if($pob->slug && $post != $pob->slug) {
                        return $this->redirect("/project/{$project->id}/updates/{$pob->slug}");
                    }
                    $viewData['post']  = $pob;
                    $show  = 'updates_post';
                }

                // sus entradas de novedades
                $blog = Blog::get($project->id);

                // Get milestones, included posts

                $milestones = ProjectMilestone::getAll($project->id, Lang::current(), $project->lang);

                $viewData['milestones']=$milestones;

                $viewData['blog'] = $blog;



                $viewData['owner'] = $project->owner;


                if (empty($user)) {
                    Message::info(Text::html('user-login-required'));
                }
            }

            if ($show == 'participate') {
                $viewData['worthcracy']=Worth::getAll();
                $limit=15;
                $pag = max(0, (int)$request->query->get('pag'));
                $viewData['investors_list']= Invest::investors($project->id, false, false, $pag * $limit, $limit, false);
                $viewData['investors_total'] = Invest::investors($project->id, false, false, 0, 0, true);
                $viewData['investors_limit'] = $limit;

                //Colaborations
                $viewData['messages'] = SupportMessage::getAll($project->id, Lang::current());

                if (empty($user)) {
                    Message::info(Text::html('user-login-required'));
                }

            }

            if ($show == 'messages' && $project->status < 3) {
                Message::info(Text::get('project-messages-closed'));
            }

            $response = new Response(View::render('project/'.$show, $viewData));
            // Force no cache if not approved
            if(!$project->isApproved()) {
                $response->headers->set('Pragma', 'no-cache');
                $response->headers->set('Cache-Control', 'no-cache, must-revalidate');
                $response->headers->set('Expires', 'Mon, 26 Jul 1997 05:00:00 GMT');
            }
            return $response;

        } else {
            Message::info('Project not public yet!');
            // no lo puede ver
            return new RedirectResponse('/');
        }
    }

    /**
     * A user mark a project as favourite
     * TODO: to microAPI
     */
    public function favouriteAction($pid, Request $request) {

        if (!Session::isLogged()) {
            return $this->redirect('/user/login?return='.urldecode('/project/favourite/'.$pid));
        }

        $user=Session::getUser()->id;

        //Calculate the date to send mail

        $project=Project::get($pid, Lang::current(false));

        if( ($project->days>1) && ($project->round==1) && ($project->amount<$project->mincost) )
        {
            $interval_days_send=round(($project->days-1)*0.8);

            $date_send=new \DateTime(date('Y-m-d'));

            $date_send=$date_send->modify("+".$interval_days_send." days");

            $date_send=$date_send->format('Y-m-d');
        }

        $favourite=new Favourite(array(
            'project' => $pid, 'user' => $user, 'date_send' => $date_send
        ));

        $favourite->save($errors);

        if ($request->isMethod('post'))
            return $this->jsonResponse(['result' => $favourite]);

        return $this->redirect('/project/' . $pid);;
    }

    /**
     * A user unmark a project as favourite
     * TODO: to microAPI
     */
    public function deleteFavouriteAction(Request $request) {
         if ($request->isMethod('post')) {
                $project = $request->request->get('project');
                $user= $request->request->get('user');

                $favourite=new Favourite(array(
                    'project' => $project, 'user' => $user
                ));

                $favourite->remove($errors);
        }

        return $this->jsonResponse(['result' => true]);
    }

    public function posterAction($pid, Request $request) {

        $project=Project::get($pid, Lang::current(false));
        try {
            $html2pdf = new Html2Pdf('P', 'A4', 'en', true, 'UTF-8', array(5,0,5,8));
            $html2pdf->setTestTdInOnePage(false);
            $html2pdf->writeHTML(View::render('poster/project.php', ["project" => $project]));
            $html2pdf->pdf->SetTitle('Poster');
            $pdf = $html2pdf->output();
    
            $response = new Response($pdf);
            $response->headers->set('Content-Type', 'application/pdf');
            return $response;
        } catch(Html2PdfException $e) {
            Message::error($e->getMessage());
            return new RedirectResponse('/project/' . $project->id );
        }
    }
}

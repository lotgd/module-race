<?php
declare(strict_types=1);

namespace LotGD\Module\Race;

use LotGD\Core\Action;
use LotGD\Core\ActionGroup;
use LotGD\Core\Game;
use LotGD\Core\Events\EventContext;
use LotGD\Core\Module as ModuleInterface;
use LotGD\Core\Models\Module as ModuleModel;
use LotGD\Core\Models\Scene;
use LotGD\Core\Models\Viewpoint;
use LotGD\Module\NewDay\Module as NewDayModule;

const MODULE = "lotgd/module-race";

class Module implements ModuleInterface {
    const Module = MODULE;
    const ModulePropertySceneId = MODULE . "/sceneIds";
    const CharacterPropertyRace = MODULE . "/race";
    const SceneRaceChoose = MODULE . "/choose";
    const SceneRaceSelect = MODULE . "/select";

    const RaceHuman = "Human";
    const RaceElf = "Elf";
    const RaceDwarf = "Dwarf";
    const RaceTroll = "Troll";

    public static function handleEvent(Game $g, EventContext $context): EventContext
    {
        $event = $context->getEvent();

        if ($event === NewDayModule::HookBeforeNewDay) {
            $context = self::handleHookBeforeNewDay($g, $context);
        } elseif ($event === "h/lotgd/core/navigate-to/" . self::SceneRaceChoose) {
            $context = self::handleSceneChoose($g, $context);
        } elseif ($event === "h/lotgd/core/navigate-to/" . self::SceneRaceSelect) {
            $context = self::handleSceneSelect($g, $context);
        }

        return $context;
    }

    public static function handleHookBeforeNewDay(Game $g, EventContext $context): EventContext
    {
        if ($g->getCharacter()->getProperty(self::CharacterPropertyRace, null) === null) {
            $context->setDataField(
                "redirect",
                $g->getEntityManager()
                    ->getRepository(Scene::class)
                    ->findOneBy(["template" => self::SceneRaceChoose])
            );
        }

        return $context;
    }

    private static function handleSceneChoose(Game $g, EventContext $context): EventContext
    {
        /** @var Viewpoint $v */
        $v = $context->getDataField("viewpoint");
        $destinationId = $g->getEntityManager()
            ->getRepository(Scene::class)
            ->findOneBy(["template" => self::SceneRaceSelect])
            ->getId();

        $actionH = new Action($destinationId, "Human", ["race" => self::RaceHuman]);
        $actionE = new Action($destinationId, "Elf", ["race" => self::RaceElf]);
        $actionD = new Action($destinationId, "Dwarf", ["race" => self::RaceDwarf]);
        $actionT = new Action($destinationId, "Troll", ["race" => self::RaceTroll]);

        $group = new ActionGroup(self::Module, "Choose", 0);
        $group->setActions([$actionH, $actionE, $actionD, $actionT]);

        // Need to have better api here
        $groups = $v->getActionGroups();
        $groups[] = $group;
        $v->setActionGroups($groups);

        return $context;
    }

    private static function handleSceneSelect(Game $g, EventContext $context): EventContext
    {
        $race = $context->getDataField("parameters")["race"];
        $continue = true;

        switch($race) {
            case self::RaceHuman:
            case self::RaceElf:
            case self::RaceDwarf:
            case self::RaceTroll:
                $g->getCharacter()->setProperty(self::CharacterPropertyRace, $race);
                break;

            default:
                // You should not end up here, but still let us cover it
                $continue = false;
        }

        if ($continue) {
            // Redirect to SceneContinue to continue the new day
            $scene = $g->getEntityManager()
                ->getRepository(Scene::class)
                ->findOneBy(["template" => NewDayModule::SceneContinue]);
        } else {
            // Redirect to GenderChoose, since the race looks invalid...
            // You should not end up here though, but you might!
            $scene = $g->getEntityManager()
                ->getRepository(Scene::class)
                ->findOneBy(["template" => self::SceneRaceChoose]);
        }

        $context->setDataField("redirect", $scene);

        return $context;
    }

    private static function getScenes()
    {
        $choose = Scene::create([
            "template" => self::SceneRaceChoose,
            "title" => "Which race do you belong to?",
            "description" => "«To which kind of people do you belong to?», silently asks you a bodyless voice.
            
            Are you one of the humans? Agile, jack of all trades, but master of none; Living mostly in villages
            and cities.
            
            Do you belong to the proud elven people? They live high among the trees of the forest, in elaborate but 
            frail looking Elvish structures that look as though they might collapse under the slightest strain,
            yet have existed for centuries. They are careful people, always aware of their surroundings.
            
            Or are you even one of the dwarfs? These noble and fierce people live deep in subterranean strongholds
            and desire privacy like none of the others, only to protect their treasures from the greed of others.
            
            Do you like swamps? As a member of the Trolls, you learned to fend for yourself from the very moment
            you crept out of your leathery egg and went on to slay your yet unhatched siblings, feasting on their
            warm bones.
            "
        ]);

        $select = Scene::create([
            "template" => self::SceneRaceSelect,
            "title" => "You have chosen your race.",
            "description" => "Your shadow makes an agreeing gesture - or was it you? You don't know, you don't care. "
                ."And you certainly should not see this text."
        ]);

        return [$choose, $select];
    }
    
    public static function onRegister(Game $g, ModuleModel $module)
    {
        // Register new day scene and "restoration" scene.
        $sceneIds = $module->getProperty(self::ModulePropertySceneId);

        if ($sceneIds === null) {
            [$choose, $select] = self::getScenes();

            $g->getEntityManager()->persist($choose);
            $g->getEntityManager()->persist($select);
            $g->getEntityManager()->flush();

            $module->setProperty(self::ModulePropertySceneId, [
                self::SceneRaceChoose => $choose->getId(),
                self::SceneRaceSelect => $select->getId()
            ]);

            // logging
            $g->getLogger()->addNotice(sprintf(
                "%s: Adds scenes (newday: %s, restoration: %s)",
                self::Module,
                $choose->getId(),
                $select->getId()
            ));
        }
    }

    public static function onUnregister(Game $g, ModuleModel $module)
    {
        // Unregister them again.
        $sceneIds = $module->getProperty(self::ModulePropertySceneId);

        if ($sceneIds !== null) {
            // delete village
            $g->getEntityManager()->getRepository(Scene::class)->find($sceneIds[self::SceneRaceChoose])->delete($g->getEntityManager());
            $g->getEntityManager()->getRepository(Scene::class)->find($sceneIds[self::SceneRaceSelect])->delete($g->getEntityManager());

            // set property to null
            $module->setProperty(self::ModulePropertySceneId, null);
        }
    }
}

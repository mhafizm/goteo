<?php
use Goteo\Core\View,
    Goteo\Library\Text,
    Goteo\Model;

$call = $this['call'];

$status = Model\Project::status();

?>
<div class="widget">
    <a class="button red" href="/call/edit/<?php echo $call->id ?>"><?php echo Text::get('regular-edit') ?></a>
    <a class="button" href="/call/<?php echo $call->id ?>" target="_blank"><?php echo Text::get('dashboard-menu-calls-preview') ?></a>

<?php if ($call->status < 3) : ?>
    <?php if ($_SESSION['assign_mode'] === true) : ?>
        <a href="/discover/call">Seguir seleccionando proyectos</a> ó
        <a class="button" href="/dashboard/calls/projects/assign_mode/off"><?php echo Text::get('dashboard-menu-calls-assign_mode-off') ?></a>
    <?php else : ?>
        <a class="button" href="/dashboard/calls/projects/assign_mode/on"><?php echo Text::get('dashboard-menu-calls-assign_mode-on') ?></a>
    <?php endif; ?>
<?php endif; ?>

    <?php if ($call->status == 1) : ?>
    <a class="button weak" href="/call/delete/<?php echo $call->id ?>" onclick="return confirm('<?php echo Text::get('dashboard-call-delete_alert') ?>')"><?php echo Text::get('regular-delete') ?></a>
    <?php endif ?>

</div>

<div class="widget gestrew">
    <div class="message">
        ESTO ES UNA VISUALIZACIÓN DE LOS PROYECTOS QUE RIEGAS.<br />
        LOS PROYECTOS QUE NO CONSIGAN EL MÍNIMO LIBERARÁN CAPITAL RIEGO
    </div>

    <?php if (!empty($call->projects)) : ?>
    <table>
        <tr>
            <th>Proyecto</th>
            <th>Estado</th>
            <th>Riego</th>
            <th></th>
        </tr>
        <?php foreach ($call->projects as $proj) :
            if ($proj->status <= 1) continue;
            ?>
        <tr>
            <td><?php echo $proj->name ?></td>
            <td><?php echo $status[$proj->status] ?></td>
            <td><?php echo $proj->amount ?></td>
            <td><?php if ($proj->amount <= 0) : ?>
                <a href="/dashboard/calls/projects/unassign/<?php echo $proj->id ?>" onclick="return confirm('Seguro que quitamos el proyecto \'<?php echo $proj->name ?>\' de la convocatoria \'<?php echo $call->name ?>\' ?')">[Quitar]</a>
            <?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php else : ?>
    <p>Ning&uacute;n proyecto</p>
    <?php endif; ?>
</div>
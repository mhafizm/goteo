<?php

use Goteo\Library\Text;

//$data = $this['data'];
$filters = $this['filters'];
?>
<div class="widget board">
    <form id="filter-form" action="/manage/donors/excel" method="get" target="_blank">

        <div style="float:left;margin:5px;">
            <label for="year-filter">A&ntilde;o aporte:</label><br />
            <select id ="year-filter" name="year">
                <option value=""<?php if (empty($filters['year'])) echo ' selected="selected"'; ?>>Todos</option>
                <option value="2013"<?php if ($filters['year']=='2013') echo ' selected="selected"'; ?>>Anterior (2013)</option>
                <option value="2014"<?php if ($filters['year']=='2014') echo ' selected="selected"'; ?>>Actual (2014)</option>
            </select>
        </div>

        <div style="float:left;margin:5px;">
            <label for="status-filter">Estado datos:</label><br />
            <select id ="status-filter" name="status">
                <option value=""<?php if ($filters['status']=='') echo ' selected="selected"'; ?>>Todos</option>
                <option value="pending"<?php if ($filters['status']=='pending') echo ' selected="selected"'; ?>>Pendientes de revision</option>
                <option value="edited"<?php if ($filters['status']=='edited') echo ' selected="selected"'; ?>>Revisados pero no confirmados</option>
                <option value="confirmed"<?php if ($filters['status']=='confirmed') echo ' selected="selected"'; ?>>Confirmados</option>
                <option value="emited"<?php if ($filters['status']=='emited') echo ' selected="selected"'; ?>>Certificado emitido</option>
                <option value="notemited"<?php if ($filters['status']=='notemited') echo ' selected="selected"'; ?>>Confirmado pero no emitido</option>
            </select>
        </div>

        <div style="float:left;margin:5px;">
            <label for="user-filter">Usuario (id/alias/email):</label><br />
            <input id="user-filter" name="user" value="<?php echo $filters['user']; ?>" />
        </div>

        <br clear="both" />

        <div style="float:left;margin:5px;">
            <input type="submit" value="Sacar datos" />
        </div>
    </form>
</div>

<?php /* 
*** ya no usamos esta página para visualizar, solo para sacar datos.
if (!empty($data)) : ?>
<div class="widget board">
    <table>
        <thead>
            <tr>
                <th>Usuario</th>
                <th>Cantidad</th>
                <th>CP</th>
                <th style="max-width: 20px;"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data as $row) : ?>
            <tr>
                <td><a href="/admin/users/?id=<?php echo $row->id; ?>" title="<?php echo $row->email; ?>"><?php echo empty($row->name) ? $row->email : $row->name; ?> <?php echo $row->nif; ?></a></td>
                <td><?php echo $row->amount; ?></td>
                <td><?php echo $row->zipcode; ?></td>
                <td>
                    <?php echo ($row->pending == $row->id) ? '' : $row->pending; ?>
                    <?php if ($row->confirmed) echo ' Confirmado';
                    elseif ($row->edited) echo ' Revisado'; ?>
                    <?php if ($row->pdf) : ?>
                        <br /><a href="/data/pdfs/donativos/<?php echo $row->pdf; ?>" target="_blank">[Ver pdf]</a><br />
                        <a href="/manage/donors/resetpdf/<?php echo md5($row->pdf); ?>" onclick="return confirm('Seguro que eliminamos este pdf de certificado?');">[Eliminar pdf]</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php elseif (empty($filters['filtered'])) : ?>
<p>hay que filtrar, hay demasiados registros</p>
<?php else : ?>
<p>No se han encontrado registros</p>
<?php endif; 
*/
?>
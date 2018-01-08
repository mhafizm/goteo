<?php if($this->channels): ?>
    
    <div class="section channels" id="channels" >
        <div class="drop-img-container">
            <img class="center-block drop-img" src="/assets/img/project/channel.svg" alt="matchfunding">
        </div>
        <h2 class="title text-center">
            <?= $this->text('home-channels-title') ?>
        </h2>    
        <div class="container" id="channel-container">
            <div class="row slider slider-channels">
            <?php foreach($this->channels as $channel): ?>
                <?php $summary = $channel->getSummary(); ?>
                <?php $background = $channel->owner_background; ?>
                <?= $channel->owner_font_color ?>

                <div class="channel col-sm-4">
                    <a href="<?= '/channel/'.$channel->id ?>">
                        <div class="widget-channel">
                            <div class="img-container" style="background-color: <?= $background ?> ">
                                <div class="img">
                                    <img class="img-responsive" src="<?= $channel->logo ? $channel->logo->getlink(200,0) : '' ?>" alt="<?= $channel->name ?>"/>
                                </div>
                            </div>
                            <div class="content" style="<?php if($background) echo ' background-color:' . $this->to_rgba($background, 0.8); if($channel->owner_font_color) echo ' color:' . $channel->owner_font_color; ?>" >
                                <div class="title">
                                    <?= $channel->name ?>
                                </div>
                                <div class="description">
                                    <?= $this->text_truncate($channel->description, 100) ?>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach ;?>
            </div>
        </div>

    </div>

<?php endif; ?>
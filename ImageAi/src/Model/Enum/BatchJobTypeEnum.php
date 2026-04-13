<?php declare(strict_types=1);

namespace Illux\ImageAi\Model\Enum;

enum BatchJobTypeEnum: string
{
    case Analysis = 'analysis';
    case SceneGeneration = 'scene_generation';
}

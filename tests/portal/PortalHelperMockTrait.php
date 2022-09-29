<?php

namespace go1\util\tests\portal;

use Doctrine\DBAL\Connection;
use go1\domain_users\clients\portal\lib\Model\Portal;
use go1\sso\domain\SSO\Services;
use go1\util\portal\PortalHelper;
use Pimple\Container;

trait PortalHelperMockTrait
{
    protected $inMemoryPortals = [];

    private function createPortalFromArray(array $options)
    {
        $data = isset($options['data']) ? $options['data'] : '[]';
        $id = $options['id'] ?? count($this->inMemoryPortals) + 1;
        $title = $title = isset($options['title']) ? $options['title'] : 'az.mygo1.com';
        return [
            'id'         => $options['id'] ?? null,
            'title'      => $title,
            'status'     => isset($options['status']) ? $options['status'] : 1,
            'is_primary' => isset($options['is_primary']) ? $options['is_primary'] : 1,
            'version'    => isset($options['version']) ? $options['version'] : PortalHelper::STABLE_VERSION,
            'data'       => is_scalar($data) ? $data : json_encode($data),
            'timestamp'  => isset($options['timestamp']) ? $options['timestamp'] : time(),
            'created'    => isset($options['created']) ? $options['created'] : time(),
        ];
    }

    /**
     * @param $options array|Portal
     * @return int
     */
    protected function createPortal($options): int
    {
        $portal = is_array($options) ? new Portal($this->createPortalFromArray($options)) : $options;
        if (!empty($this->inMemoryPortals[$portal->getId()]) || !empty($this->inMemoryPortals[$portal->getTitle()])) {
            throw new \RuntimeException('The portal already existed');
        }

        $this->inMemoryPortals[$portal->getId()] = $this->inMemoryPortals[$portal->getTitle()] = $portal;

        return $portal->getId();
    }

    protected function mockPortalHelper(Container $c)
    {
        $this->inMemoryPortals = [];
        $c->extend(PortalHelper::class, function () use ($c) {
            $portalHelper = $this->getMockBuilder(PortalHelper::class)
                ->disableOriginalConstructor()
                ->setMethods(['load'])
                ->getMock();

            $portalHelper
                ->expects($this->any())
                ->method('load')
                ->willReturnCallback(function ($nameOrId) {
                    if (isset($this->inMemoryPortals[$nameOrId])) {
                        return $this->inMemoryPortals[$nameOrId];
                    }

                    return null;
                });

            return $portalHelper;
        });
    }
}

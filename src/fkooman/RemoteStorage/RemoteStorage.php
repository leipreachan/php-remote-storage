<?php

/**
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Lesser General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Lesser General Public License for more details.
 *
 *  You should have received a copy of the GNU Lesser General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace fkooman\RemoteStorage;

use fkooman\RemoteStorage\Exception\PreconditionFailedException;
use fkooman\Json\Json;

class RemoteStorage
{
    /** @var fkooman\RemoteStorage\MetadataStorage */
    private $md;

    /** @var fkooman\RemoteStorage\DocumentStorage */
    private $d;

    /** @var fkooman\Json\Json */
    private $j;

    public function __construct(MetadataStorage $md, DocumentStorage $d)
    {
        $this->md = $md;
        $this->d = $d;
        $this->j = new Json();
        $this->j->setForceObject(true);
    }

    public function putDocument(Path $p, $contentType, $documentData, $ifMatch = null, $ifNoneMatch = null)
    {
        if (null !== $ifMatch && $ifMatch !== $this->md->getVersion($p)) {
            throw new PreconditionFailedException();
        }

        if ("*" === $ifNoneMatch && null !== $this->md->getVersion($p)) {
            throw new PreconditionFailedException();
        }

        $updatedEntities = $this->d->putDocument($p, $documentData);
        $this->md->updateDocument($p, $contentType);
        foreach ($updatedEntities as $u) {
            $this->md->updateFolder(new Path($u));
        }
    }

    public function deleteDocument(Path $p, $ifMatch = null)
    {
        if (null !== $ifMatch && $ifMatch !== $this->md->getVersion($p)) {
            throw new PreconditionFailedException();
        }
        $deletedEntities = $this->d->deleteDocument($p);
        foreach ($deletedEntities as $d) {
            $this->md->deleteNode(new Path($d));
        }

        // increment the version from increment the version from the folder
        // containing the last deleted folder and up to the user root
        foreach ($p->getFolderTreeToUserRoot() as $i) {
            if (null !== $this->md->getVersion(new Path($i))) {
                $this->md->updateFolder(new Path($i));
            }
        }
    }

    public function getVersion(Path $p)
    {
        return $this->md->getVersion($p);
    }

    public function getContentType(Path $p)
    {
        return $this->md->getContentType($p);
    }

    public function getDocument(Path $p, $ifMatch = null)
    {
        if (null !== $ifMatch) {
            // may be comma separated
            $nodeVersions = explode(",", $ifMatch);
            if (in_array($this->md->getVersion($p), $nodeVersions)) {
                throw new PreconditionFailedException();
            }
        }

        return $this->d->getDocument($p);
    }

    public function getFolder(Path $p, $ifMatch = null)
    {
        if (null !== $ifMatch) {
            // may be comma separated
            $nodeVersions = explode(",", $ifMatch);
            if (in_array($this->md->getVersion($p), $nodeVersions)) {
                throw new PreconditionFailedException();
            }
        }
        $f = array(
            "@context" => "http://remotestorage.io/spec/folder-description",
            "items" => $this->d->getFolder($p)
        );
        foreach ($f["items"] as $name => $meta) {
            $f["items"][$name]["ETag"] = $this->md->getVersion(new Path($p->getFolderPath() . $name));

            // if item is a folder we don't want Content-Type
            if (strrpos($name, "/") !== strlen($name)-1) {
                $f["items"][$name]["Content-Type"] = $this->md->getContentType(new Path($p->getFolderPath() . $name));
            }
        }

        return $this->j->encode($f);
    }
}

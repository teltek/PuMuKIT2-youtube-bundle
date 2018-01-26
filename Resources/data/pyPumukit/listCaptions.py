#!/usr/bin/python

import httplib2_monkey_patch  # noqa: F401

import sys
import json

from apiclient.errors import HttpError
from optparse import OptionParser

from py_pumukit_lib import get_authenticated_service


# NOTE: Issue #16756
# Youtube insert Captions API accepts Media MIME types: text/xml, application/octet-stream, */*
# Python discovery.py from google-api-python-client-1.2 uses mimetypes to check the MIME type
# of the file. This library returns the MIME type as None for the following types. So, the
# API throws apiclient.errors.UnknownFileType error. This is a workaround:
import mimetypes
mimetypes.add_type('application/octet-stream', '.vtt')
mimetypes.add_type('application/octet-stream', '.srt')
mimetypes.add_type('application/octet-stream', '.dfxp')
mimetypes.add_type('application/octet-stream', '.ttml')


def list_captions(youtube, video_id):
    """
    Call the API's captions.list method to list the existing caption tracks.
    """
    out = {'error': False, 'out': None}
    results = youtube.captions().list(
        part="snippet",
        videoId=video_id
    ).execute()

    out['out'] = {}
    for item in results["items"]:
        id = item["id"]
        name = item["snippet"]["name"]
        language = item["snippet"]["language"]
        out['out'][id] = "Caption track '%s(%s)' in '%s' language." % (name, id, language)

    return out

    
if __name__ == "__main__":
  parser = OptionParser()
  parser.add_option("--account", dest="account", help="Youtube account login.")
  parser.add_option("--videoid", dest="videoid", help="ID of video to update.")

  (options, args) = parser.parse_args()

  if options.videoid is None:
    exit("Please specify a valid video using --videoid= parameter")

  out = {'error': False, 'out': None}
  try:
    youtube = get_authenticated_service(options.account)
    out = list_captions(youtube, options.videoid)
  except HttpError as e:
      out['error'] = True
      out['error_out'] = "Http Error: %s" % e._get_reason()
  except:
      sys_exc_info = sys.exc_info()
      out['error'] = True
      out['error_out'] = "Unexpected error: (%s) %s" % (sys_exc_info[0], sys_exc_info[1])

  print json.dumps(out)
